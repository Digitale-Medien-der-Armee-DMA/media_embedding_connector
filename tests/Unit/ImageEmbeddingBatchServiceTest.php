<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\FileIndexingService;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingBatchService;
use OCA\MediaEmbeddingConnector\Service\MediaLabClient;
use OCA\MediaEmbeddingConnector\Service\MediaLabContractService;
use OCA\MediaEmbeddingConnector\Service\MediaLabErrorPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageEmbeddingBatchServiceTest extends TestCase
{
    public function testBatchWithThreeImagesMapsResultsByResponseIndex(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $jobs = [$this->job(10), $this->job(20), $this->job(30)];
        $prepared = [$this->prepared($jobs[0]), $this->prepared($jobs[1]), $this->prepared($jobs[2])];
        $contract->method('getDefaultModelContract')->willReturn($this->contract());
        $fileIndexing->method('prepareImageForEmbedding')->willReturnOnConsecutiveCalls(...$prepared);
        $client->method('embedImageFiles')->willReturn([
            'results' => [
                ['index' => 2, 'image_vector' => [0.3], 'model_id' => 'clip', 'embedding_dim' => 1],
                ['index' => 0, 'image_vector' => [0.1], 'model_id' => 'clip', 'embedding_dim' => 1],
                ['index' => 1, 'image_vector' => [0.2], 'model_id' => 'clip', 'embedding_dim' => 1],
            ],
            'failed' => [],
        ]);
        $fileIndexing->expects(self::exactly(3))
            ->method('indexPreparedImage')
            ->willReturnCallback(function (array $item) use ($prepared): string {
                static $seen = [];
                $seen[] = (int)$item['job']['id'];
                if (count($seen) === 3) {
                    self::assertSame([30, 10, 20], $seen);
                }
                return IndexJobRepository::STATUS_INDEXED;
            });

        $result = $service->process($jobs);

        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[10]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[20]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[30]['status']);
    }

    public function testBatchFailedItemOnlyMarksThatItemSkipped(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $jobs = [$this->job(10), $this->job(20), $this->job(30)];
        $prepared = [$this->prepared($jobs[0]), $this->prepared($jobs[1]), $this->prepared($jobs[2])];
        $contract->method('getDefaultModelContract')->willReturn($this->contract());
        $fileIndexing->method('prepareImageForEmbedding')->willReturnOnConsecutiveCalls(...$prepared);
        $client->method('embedImageFiles')->willReturn([
            'results' => [
                ['index' => 0, 'image_vector' => [0.1]],
                ['index' => 2, 'image_vector' => [0.3]],
            ],
            'failed' => [
                ['index' => 1, 'error' => 'invalid_image', 'message' => 'bad image', 'status_code' => 422],
            ],
        ]);
        $fileIndexing->method('indexPreparedImage')->willReturn(IndexJobRepository::STATUS_INDEXED);
        $fileIndexing->expects(self::once())
            ->method('skipPreparedImage')
            ->with($prepared[1], 'invalid_image');

        $result = $service->process($jobs);

        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[10]['status']);
        self::assertSame(IndexJobRepository::STATUS_SKIPPED, $result[20]['status']);
        self::assertSame('invalid_image', $result[20]['error']);
        self::assertSame(422, $result[20]['status_code']);
        self::assertFalse($result[20]['retry']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[30]['status']);
    }

    public function testRetryableBatchItemUsesRetryStatus(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $job = $this->job(10);
        $contract->method('getDefaultModelContract')->willReturn($this->contract());
        $fileIndexing->method('prepareImageForEmbedding')->willReturn($this->prepared($job));
        $client->method('embedImageFiles')->willReturn([
            'results' => [],
            'failed' => [
                ['index' => 0, 'error' => 'temporarily_unavailable', 'status_code' => 503],
            ],
        ]);

        $result = $service->process([$job]);

        self::assertSame('retry', $result[10]['status']);
        self::assertSame(503, $result[10]['status_code']);
        self::assertTrue($result[10]['retry']);
    }

    public function testPreparationFailureOnlyFailsThatJob(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $jobs = [$this->job(10), $this->job(20)];
        $prepared = $this->prepared($jobs[1]);
        $contract->method('getDefaultModelContract')->willReturn($this->contract());
        $fileIndexing->method('prepareImageForEmbedding')->willReturnOnConsecutiveCalls(
            self::throwException(new \RuntimeException('owner missing')),
            $prepared,
        );
        $client->method('embedImageFiles')->willReturn([
            'results' => [['index' => 0, 'image_vector' => [0.2]]],
            'failed' => [],
        ]);
        $fileIndexing->expects(self::once())
            ->method('indexPreparedImage')
            ->with($prepared, ['index' => 0, 'image_vector' => [0.2]])
            ->willReturn(IndexJobRepository::STATUS_INDEXED);

        $result = $service->process($jobs);

        self::assertSame(IndexJobRepository::STATUS_FAILED, $result[10]['status']);
        self::assertSame('image_preparation_failed', $result[10]['error']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[20]['status']);
    }

    public function testRepeatedBatchFailureSplitsBatchAndRetriesHalves(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $jobs = [$this->job(10, 1), $this->job(20, 1), $this->job(30, 1), $this->job(40, 1)];
        $prepared = array_map(fn (array $job): array => $this->prepared($job), $jobs);
        $contract->method('getDefaultModelContract')->willReturn($this->contract());
        $fileIndexing->method('prepareImageForEmbedding')->willReturnOnConsecutiveCalls(...$prepared);
        $client->expects(self::exactly(3))
            ->method('embedImageFiles')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new ExternalServiceException('down', 'medialab_unreachable', true)),
                ['results' => [['index' => 0, 'image_vector' => [0.1]], ['index' => 1, 'image_vector' => [0.2]]], 'failed' => []],
                ['results' => [['index' => 0, 'image_vector' => [0.3]], ['index' => 1, 'image_vector' => [0.4]]], 'failed' => []],
        );
        $fileIndexing->method('indexPreparedImage')->willReturn(IndexJobRepository::STATUS_INDEXED);

        $result = $service->process($jobs);

        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[10]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[20]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[30]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[40]['status']);
    }

    public function testPermanentBatchInputFailureSplitsImmediatelyToAvoidSkippingValidImages(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $jobs = [$this->job(10), $this->job(20), $this->job(30), $this->job(40)];
        $prepared = array_map(fn (array $job): array => $this->prepared($job), $jobs);
        $contract->method('getDefaultModelContract')->willReturn($this->contract());
        $fileIndexing->method('prepareImageForEmbedding')->willReturnOnConsecutiveCalls(...$prepared);
        $client->expects(self::exactly(7))
            ->method('embedImageFiles')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new ExternalServiceException('bad image', 'invalid_image', false, 'invalid_image', 422)),
                self::throwException(new ExternalServiceException('bad image', 'invalid_image', false, 'invalid_image', 422)),
                ['results' => [['index' => 0, 'image_vector' => [0.1]]], 'failed' => []],
                self::throwException(new ExternalServiceException('bad image', 'invalid_image', false, 'invalid_image', 422)),
                self::throwException(new ExternalServiceException('bad image', 'invalid_image', false, 'invalid_image', 422)),
                ['results' => [['index' => 0, 'image_vector' => [0.3]]], 'failed' => []],
                ['results' => [['index' => 0, 'image_vector' => [0.4]]], 'failed' => []],
            );
        $fileIndexing->method('indexPreparedImage')->willReturn(IndexJobRepository::STATUS_INDEXED);
        $fileIndexing->expects(self::once())
            ->method('process')
            ->with($jobs[1])
            ->willReturn(IndexJobRepository::STATUS_SKIPPED);

        $result = $service->process($jobs);

        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[10]['status']);
        self::assertSame(IndexJobRepository::STATUS_SKIPPED, $result[20]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[30]['status']);
        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[40]['status']);
    }

    public function testSingleEndpointFallbackWhenBatchIsUnsupported(): void
    {
        [$service, $contract, $client, $fileIndexing] = $this->service();
        $job = $this->job(10);
        $contract->method('getDefaultModelContract')->willReturn([
            'image_input' => ['batch_max_items' => 0],
        ]);
        $client->expects(self::never())->method('embedImageFiles');
        $fileIndexing->expects(self::once())->method('process')->with($job)->willReturn(IndexJobRepository::STATUS_INDEXED);

        $result = $service->process([$job]);

        self::assertSame(IndexJobRepository::STATUS_INDEXED, $result[10]['status']);
    }

    /**
     * @return array{0: ImageEmbeddingBatchService, 1: MediaLabContractService, 2: MediaLabClient, 3: FileIndexingService}
     */
    private function service(): array
    {
        $contract = $this->createMock(MediaLabContractService::class);
        $client = $this->createMock(MediaLabClient::class);
        $fileIndexing = $this->createMock(FileIndexingService::class);
        $config = $this->createMock(AppConfig::class);
        $config->method('getImageBatchSize')->willReturn(8);
        $config->method('getImageBatchRequestTimeout')->willReturn(120);
        $logger = $this->createMock(LoggerInterface::class);
        return [
            new ImageEmbeddingBatchService($contract, $client, new MediaLabErrorPolicy(), $fileIndexing, $config, $logger),
            $contract,
            $client,
            $fileIndexing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(): array
    {
        return [
            'image_input' => [
                'batch_max_items' => 8,
                'batch_max_total_mb' => 32,
                'batch_max_total_pixels' => 50000000,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $id, int $attempts = 0): array
    {
        return ['id' => $id, 'attempts' => $attempts, 'action' => IndexJobRepository::ACTION_INDEX];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function prepared(array $job): array
    {
        return [
            'status' => 'ready',
            'job' => $job,
            'tmp_path' => '/tmp/image-' . $job['id'],
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'pixel_count' => 100,
        ];
    }
}
