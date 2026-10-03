<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Service\StructureMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class StructureMigrationJob extends TimedJob
{
    public function __construct(ITimeFactory $time, private StructureMigrationService $migration)
    {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(false);
    }
    protected function run(mixed $argument): void { $this->migration->runSlice(20); }
}
