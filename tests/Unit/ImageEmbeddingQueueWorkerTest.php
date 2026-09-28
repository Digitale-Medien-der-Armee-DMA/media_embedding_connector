<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingBatchService;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingQueueWorker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageEmbeddingQueueWorkerTest extends TestCase
{
    private IndexJobRepository&MockObject $jobs;
    private ImageEmbeddingBatchService&MockObject $batchService;
    private AppConfig&MockObject $config;
    private ImageEmbeddingQueueWorker $worker;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(IndexJobRepository::class);
        $this->batchService = $this->createMock(ImageEmbeddingBatchService::class);
        $this->config = $this->createMock(AppConfig::class);
        $this->config->method('isIndexingEnabled')->willReturn(true);
        $this->config->method('getImageBatchSize')->willReturn(8);
        $this->config->method('getImageBatchMaxParallelRequestsPerToken')->willReturn(4);
        $this->worker = new ImageEmbeddingQueueWorker(
            $this->jobs,
            $this->batchService,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testBatchExceptionRetriesClaimedJobsInsteadOfLeavingThemRunning(): void
    {
        $claimed = [
            ['id' => 10, 'attempts' => 0],
            ['id' => 20, 'attempts' => 1],
        ];
        $this->jobs->method('claimImageEmbeddingBatch')->with(8, 32)->willReturn($claimed);
        $this->batchService->method('process')->with($claimed)->willThrowException(
            new ExternalServiceException('MediaLab unavailable.', 'medialab_unreachable', true, null, 503),
        );

        $retryCalls = [];
        $this->jobs->expects(self::exactly(2))
            ->method('markRetry')
            ->willReturnCallback(static function (
                int $jobId,
                int $attempts,
                int $nextAttemptAt,
                string $error,
                ?int $statusCode,
            ) use (&$retryCalls): void {
                $retryCalls[] = compact('jobId', 'attempts', 'nextAttemptAt', 'error', 'statusCode');
            });
        $this->jobs->expects(self::never())->method('markFailed');

        $before = time();
        self::assertSame(2, $this->worker->processNextBatch());

        self::assertSame(10, $retryCalls[0]['jobId']);
        self::assertSame(1, $retryCalls[0]['attempts']);
        self::assertGreaterThanOrEqual($before + 60, $retryCalls[0]['nextAttemptAt']);
        self::assertSame('medialab_unreachable', $retryCalls[0]['error']);
        self::assertSame(503, $retryCalls[0]['statusCode']);
        self::assertSame(20, $retryCalls[1]['jobId']);
        self::assertSame(2, $retryCalls[1]['attempts']);
        self::assertGreaterThanOrEqual($before + 300, $retryCalls[1]['nextAttemptAt']);
    }

    public function testUnexpectedBatchThrowableFailsClaimedJobs(): void
    {
        $claimed = [
            ['id' => 10, 'attempts' => 0],
            ['id' => 20, 'attempts' => 0],
        ];
        $this->jobs->method('claimImageEmbeddingBatch')->willReturn($claimed);
        $this->batchService->method('process')->willThrowException(new \RuntimeException('boom'));

        $failedCalls = [];
        $this->jobs->expects(self::exactly(2))
            ->method('markFailed')
            ->willReturnCallback(static function (int $jobId, string $error) use (&$failedCalls): void {
                $failedCalls[] = compact('jobId', 'error');
            });
        $this->jobs->expects(self::never())->method('markRetry');

        $this->worker->processNextBatch();

        self::assertSame([
            ['jobId' => 10, 'error' => 'medialab_batch_failed'],
            ['jobId' => 20, 'error' => 'medialab_batch_failed'],
        ], $failedCalls);
    }

    public function testResultsAreMappedToJobStatuses(): void
    {
        $claimed = [
            ['id' => 1, 'attempts' => 0],
            ['id' => 2, 'attempts' => 0],
            ['id' => 3, 'attempts' => 0],
            ['id' => 4, 'attempts' => 0],
        ];
        $this->jobs->method('claimImageEmbeddingBatch')->willReturn($claimed);
        $this->batchService->method('process')->willReturn([
            1 => ['status' => IndexJobRepository::STATUS_INDEXED],
            2 => ['status' => IndexJobRepository::STATUS_SKIPPED],
            3 => ['status' => 'retry', 'error' => 'rate_limited', 'status_code' => 429],
        ]);

        $completed = [];
        $this->jobs->method('markComplete')->willReturnCallback(static function (int $id, string $status) use (&$completed): void {
            $completed[$id] = $status;
        });
        $this->jobs->expects(self::once())->method('markRetry')->with(3, 1, self::anything(), 'rate_limited', 429);
        $this->jobs->expects(self::once())->method('markFailed')->with(4, 'missing_batch_result', null);

        $this->worker->processNextBatch();

        self::assertSame([1 => IndexJobRepository::STATUS_INDEXED, 2 => IndexJobRepository::STATUS_SKIPPED], $completed);
    }

    public function testDrainKeepsClaimingUntilQueueIsEmpty(): void
    {
        $this->jobs->expects(self::exactly(3))
            ->method('claimImageEmbeddingBatch')
            ->willReturnOnConsecutiveCalls(
                [['id' => 1, 'attempts' => 0], ['id' => 2, 'attempts' => 0]],
                [['id' => 3, 'attempts' => 0]],
                [],
            );
        $this->batchService->method('process')->willReturnCallback(static function (array $jobs): array {
            $results = [];
            foreach ($jobs as $job) {
                $results[(int)$job['id']] = ['status' => IndexJobRepository::STATUS_INDEXED];
            }
            return $results;
        });

        self::assertSame(['batches' => 2, 'items' => 3], $this->worker->drain(60));
    }
}
