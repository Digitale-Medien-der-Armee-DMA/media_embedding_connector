<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Db\SearchSessionRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CleanupSearchSessionsJob extends TimedJob
{
    public function __construct(ITimeFactory $time, private SearchSessionRepository $sessions)
    {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void
    {
        $this->sessions->purgeExpired();
    }
}
