<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use Psr\Log\LoggerInterface;

/**
 * Drains queued image jobs in batches. Both the cron worker job and the
 * long-running occ worker use it; the lock-free claim in
 * {@see IndexJobRepository::claimImageEmbeddingBatch()} keeps concurrent
 * workers from processing the same job.
 */
class ImageEmbeddingQueueWorker
{
    private const MAX_ATTEMPTS = 5;
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    public function __construct(
        private IndexJobRepository $jobs,
        private ImageEmbeddingBatchService $batchService,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Processes batches until the queue has nothing ready, indexing is
     * disabled, or the time budget is used up. A batch that is already
     * running is always finished, so a run can exceed the budget by one
     * request timeout.
     *
     * @return array{batches: int, items: int}
     */
    public function drain(int $timeBudgetSeconds): array
    {
        $deadline = microtime(true) + (float)max(1, $timeBudgetSeconds);
        $batches = 0;
        $items = 0;
        while (microtime(true) < $deadline && $this->config->isIndexingEnabled()) {
            $claimed = $this->processNextBatch();
            if ($claimed === 0) {
                break;
            }
            ++$batches;
            $items += $claimed;
        }
        return ['batches' => $batches, 'items' => $items];
    }

    /**
     * Claims and processes one batch.
     *
     * @return int number of claimed jobs; 0 when nothing is ready or all
     *             parallel slots are in use
     */
    public function processNextBatch(): int
    {
        if (!$this->config->isIndexingEnabled()) {
            return 0;
        }

        $batchSize = max(1, min(64, $this->config->getImageBatchSize()));
        $runningItemLimit = $batchSize * max(1, $this->config->getImageBatchMaxParallelRequestsPerToken());
        $jobs = $this->jobs->claimImageEmbeddingBatch($batchSize, $runningItemLimit);
        if ($jobs === []) {
            return 0;
        }

        try {
            $results = $this->batchService->process($jobs);
        } catch (ExternalServiceException $e) {
            $this->markClaimedJobsAfterBatchException($jobs, $e);
            return count($jobs);
        } catch (\Throwable $e) {
            foreach ($jobs as $job) {
                $this->jobs->markFailed((int)$job['id'], 'medialab_batch_failed');
            }
            $this->logger->error('Media Embedding Service image embedding batch failed unexpectedly', [
                'app' => 'media_embedding_connector',
                'job_ids' => array_map(static fn (array $job): int => (int)$job['id'], $jobs),
                'exception' => $e,
            ]);
            return count($jobs);
        }

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
                $this->jobs->markRetry(
                    $jobId,
                    $attempts,
                    time() + $this->backoffSeconds($attempts),
                    (string)($result['error'] ?? 'medialab_batch_retry'),
                    is_int($result['status_code'] ?? null) ? $result['status_code'] : null,
                );
                continue;
            }
            if ($status === IndexJobRepository::STATUS_INDEXED) {
                $this->jobs->markComplete($jobId, IndexJobRepository::STATUS_INDEXED);
                continue;
            }
            if ($status === IndexJobRepository::STATUS_SKIPPED) {
                $this->jobs->markComplete($jobId, IndexJobRepository::STATUS_SKIPPED);
                continue;
            }
            $this->jobs->markFailed(
                $jobId,
                (string)($result['error'] ?? 'medialab_batch_failed'),
                is_int($result['status_code'] ?? null) ? $result['status_code'] : null,
            );
        }

        return count($jobs);
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = self::BACKOFF_SECONDS[min(max(0, $attempts - 1), count(self::BACKOFF_SECONDS) - 1)];
        return $base + random_int(0, max(1, min(30, (int)floor($base / 10))));
    }

    /**
     * Retry jobs keep their queued row with a later next_attempt_at; the
     * worker picks them up once that time has passed.
     *
     * @param list<array<string, mixed>> $jobs
     */
    private function markClaimedJobsAfterBatchException(array $jobs, ExternalServiceException $e): void
    {
        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            $attempts = (int)($job['attempts'] ?? 0) + 1;
            if ($e->isRetryable() && $attempts < self::MAX_ATTEMPTS) {
                $this->jobs->markRetry(
                    $jobId,
                    $attempts,
                    time() + $this->backoffSeconds($attempts),
                    $e->getPublicCode(),
                    $e->getCode() ?: null,
                );
                continue;
            }
            $this->jobs->markFailed($jobId, $e->getPublicCode(), $e->getCode() ?: null);
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
