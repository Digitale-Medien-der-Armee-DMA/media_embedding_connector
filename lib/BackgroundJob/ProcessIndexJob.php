<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\FileIndexingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class ProcessIndexJob extends QueuedJob
{
    private const MAX_ATTEMPTS = 5;
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    public function __construct(
        ITimeFactory $time,
        private IndexJobRepository $jobs,
        private FileIndexingService $indexing,
        private AppConfig $config,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setAllowParallelRuns(true);
    }

    protected function run(mixed $argument): void
    {
        $jobId = is_array($argument) ? (int)($argument['job_id'] ?? 0) : 0;
        if ($jobId <= 0 || !$this->config->isIndexingEnabled()) {
            return;
        }

        $job = $this->jobs->claim($jobId, $this->config->getMaxParallelEmbedRequests());
        if ($job === null) {
            $current = $this->jobs->findById($jobId);
            if ($current === null || ($current['status'] ?? null) !== IndexJobRepository::STATUS_QUEUED) {
                return;
            }

            $now = time();
            $nextAttemptAt = (int)($current['next_attempt_at'] ?? 0);
            $runAfter = $nextAttemptAt > $now ? $nextAttemptAt : $now + 30;
            $this->jobList->scheduleAfter(self::class, $runAfter, ['job_id' => $jobId]);
            return;
        }

        try {
            $status = $this->indexing->process($job);
            $this->jobs->markComplete(
                $jobId,
                $status === 'skipped' ? IndexJobRepository::STATUS_SKIPPED : IndexJobRepository::STATUS_INDEXED,
            );
        } catch (ExternalServiceException $e) {
            $attempts = (int)$job['attempts'] + 1;
            if ($e->isRetryable() && $attempts < self::MAX_ATTEMPTS) {
                $backoffIndex = max(0, min($attempts - 1, count(self::BACKOFF_SECONDS) - 1));
                $delay = self::BACKOFF_SECONDS[$backoffIndex];
                $runAfter = time() + $delay;
                $this->jobs->markRetry($jobId, $attempts, $runAfter, $e->getPublicCode());
                $this->jobList->scheduleAfter(self::class, $runAfter, ['job_id' => $jobId]);
                return;
            }
            $this->jobs->markFailed($jobId, $e->getPublicCode());
            $this->logger->error('Media Embedding Service indexing job failed', [
                'app' => 'media_embedding_connector',
                'job_id' => $jobId,
                'error_code' => $e->getPublicCode(),
                'exception_class' => $e::class,
            ]);
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, 'indexing_failed');
            $this->logger->error('Media Embedding Service indexing job failed unexpectedly', [
                'app' => 'media_embedding_connector',
                'job_id' => $jobId,
                'exception' => $e,
            ]);
        }
    }
}
