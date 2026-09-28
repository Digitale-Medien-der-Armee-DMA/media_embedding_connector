<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Service\BackfillScanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Advances the persistent backfill scan for a bounded time on each cron run.
 * Registered through appinfo/info.xml; it does nothing unless a backfill was
 * started and is not paused.
 */
class BackfillScanJob extends TimedJob
{
    private const TIME_BUDGET_SECONDS = 60;

    public function __construct(
        ITimeFactory $time,
        private BackfillScanner $scanner,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void
    {
        $this->scanner->runSlice(self::TIME_BUDGET_SECONDS);
    }
}
