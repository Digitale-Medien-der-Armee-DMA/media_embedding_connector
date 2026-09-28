<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes indexed and skipped job rows after the configured retention period.
 * The authoritative indexing state lives in media_embed_idx_files and
 * media_embed_skips; failed jobs are kept for inspection and retry.
 */
class CleanupIndexJobsJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private IndexJobRepository $jobs,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(6 * 3600);
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void
    {
        $finishedBefore = time() - $this->config->getJobRetentionDays() * 86400;
        $deleted = $this->jobs->purgeCompleted($finishedBefore);
        if ($deleted > 0) {
            $this->logger->info('Media Embedding Service removed completed index jobs', [
                'app' => 'media_embedding_connector',
                'deleted' => $deleted,
            ]);
        }
    }
}
