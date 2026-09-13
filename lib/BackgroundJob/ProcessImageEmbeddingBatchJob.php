<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingBatchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class ProcessImageEmbeddingBatchJob extends QueuedJob
{
    private const MAX_ATTEMPTS = 5;
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    public function __construct(
        ITimeFactory $time,
        private IndexJobRepository $jobs,
        private ImageEmbeddingBatchService $batchService,
        private AppConfig $config,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setAllowParallelRuns(true);
    }

    protected function run(mixed $argument): void
    {
        if (!$this->config->isIndexingEnabled()) {
            return;
        }

        $batchSize = is_array($argument) && isset($argument['batch_size'])
            ? max(1, min(64, (int)$argument['batch_size']))
            : $this->config->getImageBatchSize();
        $runningItemLimit = $batchSize * $this->config->getImageBatchMaxParallelRequestsPerToken();
        $jobs = $this->jobs->claimImageEmbeddingBatch($batchSize, $runningItemLimit);
        if ($jobs === []) {
            return;
        }

        try {
            $results = $this->batchService->process($jobs);
        } catch (ExternalServiceException $e) {
            $this->markClaimedJobsAfterBatchException($jobs, $e, $batchSize);
            return;
        } catch (\Throwable $e) {
            foreach ($jobs as $job) {
                $this->jobs->markFailed((int)$job['id'], 'medialab_batch_failed');
            }
            $this->logger->error('Media Embedding Service image embedding batch failed unexpectedly', [
                'app' => 'media_embedding_connector',
                'job_ids' => array_map(static fn (array $job): int => (int)$job['id'], $jobs),
                'exception' => $e,
            ]);
            return;
        }

        $nextRetryAt = null;
        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            $result = $results[$jobId] ?? [
                'status' => IndexJobRepository::STATUS_FAILED,
                'error' => 'missing_batch_result',
                'status_code' => null,
            ];
            $status = (string)($result['status'] ?? IndexJobRepository::STATUS_FAILED);
            if ($status === 'retry') {
                $attempts = (int)$job['attempts'] + 1;
                $runAfter = time() + $this->backoffSeconds($attempts);
                $nextRetryAt = $nextRetryAt === null ? $runAfter : min($nextRetryAt, $runAfter);
                $this->jobs->markRetry(
                    $jobId,
                    $attempts,
                    $runAfter,
                    (string)($result['error'] ?? 'medialab_batch_retry'),
                    is_int($result['status_code'] ?? null) ? $result['status_code'] : null,
                );
                continue;
            }
            if ($status === IndexJobRepository::STATUS_INDEXED || $status === 'indexed') {
                $this->jobs->markComplete($jobId, IndexJobRepository::STATUS_INDEXED);
                continue;
            }
            if ($status === IndexJobRepository::STATUS_SKIPPED || $status === 'skipped') {
                $this->jobs->markComplete($jobId, IndexJobRepository::STATUS_SKIPPED);
                continue;
            }
            $this->jobs->markFailed(
                $jobId,
                (string)($result['error'] ?? 'medialab_batch_failed'),
                is_int($result['status_code'] ?? null) ? $result['status_code'] : null,
            );
        }

        if ($nextRetryAt !== null) {
            $this->jobList->scheduleAfter(self::class, $nextRetryAt, ['batch_size' => $batchSize]);
        }
        if (count($jobs) >= $batchSize) {
            $this->jobList->add(self::class, ['batch_size' => $batchSize]);
        }
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = self::BACKOFF_SECONDS[min(max(0, $attempts - 1), count(self::BACKOFF_SECONDS) - 1)];
        return $base + random_int(0, max(1, min(30, (int)floor($base / 10))));
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function markClaimedJobsAfterBatchException(array $jobs, ExternalServiceException $e, int $batchSize): void
    {
        $nextRetryAt = null;
        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            $attempts = (int)($job['attempts'] ?? 0) + 1;
            if ($e->isRetryable() && $attempts < self::MAX_ATTEMPTS) {
                $runAfter = time() + $this->backoffSeconds($attempts);
                $nextRetryAt = $nextRetryAt === null ? $runAfter : min($nextRetryAt, $runAfter);
                $this->jobs->markRetry($jobId, $attempts, $runAfter, $e->getPublicCode(), $e->getCode() ?: null);
                continue;
            }
            $this->jobs->markFailed($jobId, $e->getPublicCode(), $e->getCode() ?: null);
        }

        if ($nextRetryAt !== null) {
            $this->jobList->scheduleAfter(self::class, $nextRetryAt, [
                'batch_size' => $batchSize,
            ]);
        }

        $this->logger->error('Media Embedding Service image embedding batch failed', [
            'app' => 'media_embedding_connector',
            'job_ids' => array_map(static fn (array $job): int => (int)$job['id'], $jobs),
            'error_code' => $e->getPublicCode(),
            'status_code' => $e->getCode() ?: null,
            'retryable' => $e->isRetryable(),
        ]);
    }
}
