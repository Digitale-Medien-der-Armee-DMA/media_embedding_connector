<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessIndexJob;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\FileIndexingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessIndexJobTest extends TestCase
{
    private IndexJobRepository&MockObject $jobs;
    private FileIndexingService&MockObject $indexing;
    private AppConfig&MockObject $config;
    private IJobList&MockObject $jobList;
    private LoggerInterface&MockObject $logger;
    private TestableProcessIndexJob $job;

    protected function setUp(): void
    {
        $this->jobs = $this->createMock(IndexJobRepository::class);
        $this->indexing = $this->createMock(FileIndexingService::class);
        $this->config = $this->createMock(AppConfig::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config->method('isIndexingEnabled')->willReturn(true);
        $this->config->method('getMaxParallelEmbedRequests')->willReturn(4);
        $this->job = new TestableProcessIndexJob(
            $this->createStub(ITimeFactory::class),
            $this->jobs,
            $this->indexing,
            $this->config,
            $this->jobList,
            $this->logger,
        );
    }

    /**
     * @dataProvider noRequeueProvider
     * @param array<string, mixed>|null $current
     */
    public function testClaimMissDoesNotRequeueInactiveJob(?array $current): void
    {
        $this->jobs->method('claim')->willReturn(null);
        $this->jobs->method('findById')->willReturn($current);
        $this->jobList->expects(self::never())->method('scheduleAfter');

        $this->job->executeForTest(['job_id' => 7]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|null}>
     */
    public static function noRequeueProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'indexed' => [['status' => IndexJobRepository::STATUS_INDEXED]];
        yield 'skipped' => [['status' => IndexJobRepository::STATUS_SKIPPED]];
        yield 'failed' => [['status' => IndexJobRepository::STATUS_FAILED]];
        yield 'running' => [['status' => IndexJobRepository::STATUS_RUNNING]];
    }

    public function testFutureQueuedJobUsesNextAttemptAt(): void
    {
        $runAfter = time() + 600;
        $this->jobs->method('claim')->willReturn(null);
        $this->jobs->method('findById')->willReturn([
            'status' => IndexJobRepository::STATUS_QUEUED,
            'next_attempt_at' => $runAfter,
        ]);
        $this->jobList->expects(self::once())
            ->method('scheduleAfter')
            ->with(ProcessIndexJob::class, $runAfter, ['job_id' => 7]);

        $this->job->executeForTest(['job_id' => 7]);
    }

    public function testDueQueuedJobIsDelayedByAtLeastThirtySeconds(): void
    {
        $before = time();
        $this->jobs->method('claim')->willReturn(null);
        $this->jobs->method('findById')->willReturn([
            'status' => IndexJobRepository::STATUS_QUEUED,
            'next_attempt_at' => $before - 1,
        ]);
        $this->jobList->expects(self::once())
            ->method('scheduleAfter')
            ->with(
                ProcessIndexJob::class,
                self::greaterThanOrEqual($before + 30),
                ['job_id' => 7],
            );

        $this->job->executeForTest(['job_id' => 7]);
    }

    /**
     * @dataProvider successfulStatusProvider
     */
    public function testSuccessfulJobIsCompleted(string $processedStatus, string $storedStatus): void
    {
        $job = ['id' => 7, 'attempts' => 0];
        $this->jobs->method('claim')->willReturn($job);
        $this->indexing->method('process')->with($job)->willReturn($processedStatus);
        $this->jobs->expects(self::once())->method('markComplete')->with(7, $storedStatus);

        $this->job->executeForTest(['job_id' => 7]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function successfulStatusProvider(): iterable
    {
        yield 'indexed' => ['indexed', IndexJobRepository::STATUS_INDEXED];
        yield 'skipped' => ['skipped', IndexJobRepository::STATUS_SKIPPED];
    }

    public function testRetryableFailureKeepsBackoffAndAttemptLimit(): void
    {
        $job = ['id' => 7, 'attempts' => 0];
        $this->jobs->method('claim')->willReturn($job);
        $this->indexing->method('process')->willThrowException(
            new ExternalServiceException('Unavailable.', 'embedding_backend_unavailable', true),
        );
        $this->jobs->expects(self::once())
            ->method('markRetry')
            ->with(
                7,
                1,
                self::greaterThanOrEqual(time() + 60),
                'embedding_backend_unavailable',
            );
        $this->jobList->expects(self::once())
            ->method('scheduleAfter')
            ->with(
                ProcessIndexJob::class,
                self::greaterThanOrEqual(time() + 60),
                ['job_id' => 7],
            );

        $this->job->executeForTest(['job_id' => 7]);
    }

    public function testRetryableFailureAtAttemptLimitIsMarkedFailed(): void
    {
        $job = ['id' => 7, 'attempts' => 4];
        $this->jobs->method('claim')->willReturn($job);
        $this->indexing->method('process')->willThrowException(
            new ExternalServiceException('Unavailable.', 'embedding_backend_unavailable', true),
        );
        $this->jobs->expects(self::never())->method('markRetry');
        $this->jobs->expects(self::once())
            ->method('markFailed')
            ->with(7, 'embedding_backend_unavailable');
        $this->jobList->expects(self::never())->method('scheduleAfter');

        $this->job->executeForTest(['job_id' => 7]);
    }
}

class TestableProcessIndexJob extends ProcessIndexJob
{
    /**
     * @param array<string, int> $argument
     */
    public function executeForTest(array $argument): void
    {
        $this->run($argument);
    }
}
