<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessImageEmbeddingBatchJob;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingBatchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessImageEmbeddingBatchJobTest extends TestCase
{
    private IndexJobRepository&MockObject $jobs;
    private ImageEmbeddingBatchService&MockObject $batchService;
    private AppConfig&MockObject $config;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;
    private TestableProcessImageEmbeddingBatchJob $job;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(IndexJobRepository::class);
        $this->batchService = $this->createMock(ImageEmbeddingBatchService::class);
        $this->config = $this->createMock(AppConfig::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config->method('isIndexingEnabled')->willReturn(true);
        $this->config->method('getImageBatchSize')->willReturn(8);
        $this->config->method('getImageBatchMaxParallelRequestsPerToken')->willReturn(4);
        $this->job = new TestableProcessImageEmbeddingBatchJob(
            $this->createStub(ITimeFactory::class),
            $this->jobs,
            $this->batchService,
            $this->config,
            $this->jobList,
            $this->logger,
        );
    }

    public function testBatchExceptionRetriesClaimedJobsInsteadOfLeavingThemRunning(): void
    {
        $claimed = [
            ['id' => 10, 'attempts' => 0],
            ['id' => 20, 'attempts' => 1],
        ];
        $this->jobs->method('claimImageEmbeddingBatch')->willReturn($claimed);
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
        $this->jobList->expects(self::once())->method('scheduleAfter');

        $before = time();
        $this->job->executeForTest(['batch_size' => 8]);

        self::assertCount(2, $retryCalls);
        self::assertSame(10, $retryCalls[0]['jobId']);
        self::assertSame(1, $retryCalls[0]['attempts']);
        self::assertGreaterThanOrEqual($before + 60, $retryCalls[0]['nextAttemptAt']);
        self::assertSame('medialab_unreachable', $retryCalls[0]['error']);
        self::assertSame(503, $retryCalls[0]['statusCode']);
        self::assertSame(20, $retryCalls[1]['jobId']);
        self::assertSame(2, $retryCalls[1]['attempts']);
        self::assertGreaterThanOrEqual($before + 300, $retryCalls[1]['nextAttemptAt']);
        self::assertSame('medialab_unreachable', $retryCalls[1]['error']);
        self::assertSame(503, $retryCalls[1]['statusCode']);
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
        $this->jobList->expects(self::never())->method('scheduleAfter');

        $this->job->executeForTest(['batch_size' => 8]);

        self::assertSame([
            ['jobId' => 10, 'error' => 'medialab_batch_failed'],
            ['jobId' => 20, 'error' => 'medialab_batch_failed'],
        ], $failedCalls);
    }
}

class TestableProcessImageEmbeddingBatchJob extends ProcessImageEmbeddingBatchJob
{
    /**
     * @param array<string, int> $argument
     */
    public function executeForTest(array $argument): void
    {
        $this->run($argument);
    }
}
