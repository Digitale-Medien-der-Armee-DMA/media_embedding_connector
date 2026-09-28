<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingQueueWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Cron-driven image worker. Slot 0 is registered through appinfo/info.xml
 * with a null argument; it adds slots 1..n-1 so several cron processes can
 * drain the queue in parallel. Each run claims batches for a bounded time.
 *
 * Enqueueing an image never touches this job's oc_jobs row, so its
 * last_checked value is not reset while a scan is adding files.
 */
class ImageEmbeddingWorkerJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private ImageEmbeddingQueueWorker $worker,
        private AppConfig $config,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(true);
    }

    protected function run(mixed $argument): void
    {
        $slot = is_array($argument) ? max(0, (int)($argument['slot'] ?? 0)) : 0;
        $slots = $this->config->getImageWorkerSlots();
        if ($slot >= $slots) {
            $this->jobList->remove(self::class, $argument);
            return;
        }
        if ($slot === 0) {
            $this->ensureSlots($slots);
        }
        if (!$this->config->isIndexingEnabled()) {
            return;
        }

        $stats = $this->worker->drain($this->config->getWorkerTimeBudget());
        if ($stats['items'] > 0) {
            $this->logger->info('Media Embedding Service image worker run finished', [
                'app' => 'media_embedding_connector',
                'slot' => $slot,
                'batches' => $stats['batches'],
                'items' => $stats['items'],
            ]);
        }
    }

    private function ensureSlots(int $slots): void
    {
        for ($slot = 1; $slot < $slots; ++$slot) {
            $argument = ['slot' => $slot];
            if (!$this->jobList->has(self::class, $argument)) {
                $this->jobList->add(self::class, $argument);
            }
        }
    }
}
