<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCP\BackgroundJob\QueuedJob;

/**
 * @deprecated 0.4.0 Replaced by the backfill scan (BackfillScanJob). The class remains
 * so entries queued by earlier versions are consumed without errors after
 * an upgrade; the queued work itself is picked up by the replacement.
 */
class ScanBackfillFolderJob extends QueuedJob
{
    protected function run(mixed $argument): void
    {
    }
}
