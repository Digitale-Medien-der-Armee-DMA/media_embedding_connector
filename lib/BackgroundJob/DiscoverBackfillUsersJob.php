<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\AppAccessPolicy;
use OCA\MediaEmbeddingConnector\Service\IndexLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;

class DiscoverBackfillUsersJob extends QueuedJob
{
    private const BATCH_SIZE = 100;

    public function __construct(
        ITimeFactory $time,
        private IUserManager $userManager,
        private IJobList $jobList,
        private AppConfig $config,
        private AppAccessPolicy $accessPolicy,
        private IndexLifecycleService $indexLifecycle,
    ) {
        parent::__construct($time);
        $this->setAllowParallelRuns(false);
    }

    protected function run(mixed $argument): void
    {
        if (!$this->config->isIndexingEnabled() || $this->indexLifecycle->isBackfillPaused()) {
            return;
        }

        $offset = is_array($argument) ? max(0, (int)($argument['offset'] ?? 0)) : 0;
        $users = iterator_to_array($this->userManager->getSeenUsers($offset, self::BATCH_SIZE), false);
        foreach ($users as $user) {
            if (!$this->accessPolicy->isUserAllowed($user)) {
                continue;
            }
            $this->jobList->add(ScanBackfillFolderJob::class, [
                'user_id' => $user->getUID(),
                'path' => '',
            ]);
        }

        if (count($users) === self::BATCH_SIZE) {
            $this->jobList->add(self::class, ['offset' => $offset + self::BATCH_SIZE]);
        }
    }
}
