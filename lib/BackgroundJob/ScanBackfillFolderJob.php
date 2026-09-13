<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\BackgroundJob;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Service\AppAccessPolicy;
use OCA\MediaEmbeddingConnector\Service\ImageEligibilityService;
use OCA\MediaEmbeddingConnector\Service\IndexJobScheduler;
use OCA\MediaEmbeddingConnector\Service\IndexLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

class ScanBackfillFolderJob extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private IRootFolder $rootFolder,
        private IJobList $jobList,
        private IndexJobScheduler $scheduler,
        private AppConfig $config,
        private AppAccessPolicy $accessPolicy,
        private ImageEligibilityService $eligibilityService,
        private IndexLifecycleService $indexLifecycle,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void
    {
        if (!$this->config->isIndexingEnabled() || $this->indexLifecycle->isBackfillPaused()) {
            return;
        }
        if (!is_array($argument)) {
            return;
        }

        $userId = trim((string)($argument['user_id'] ?? ''));
        $path = trim((string)($argument['path'] ?? ''), '/');
        if ($userId === '') {
            return;
        }
        if (!$this->accessPolicy->isUserIdAllowed($userId)) {
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folder = $path === '' ? $userFolder : $userFolder->get($path);
            if (!$folder instanceof Folder) {
                return;
            }

            foreach ($folder->getDirectoryListing() as $node) {
                $relativePath = $userFolder->getRelativePath($node->getPath());
                if ($node instanceof Folder && $relativePath !== null) {
                    $this->jobList->add(self::class, [
                        'user_id' => $userId,
                        'path' => $relativePath,
                    ]);
                    continue;
                }

                if ($node instanceof File && $this->eligibilityService->isIndexingCandidate($node->getMimeType(), $node->getName())) {
                    $this->scheduler->enqueueIndex(
                        (string)$node->getId(),
                        $node->getOwner()?->getUID() ?? $userId,
                        $node->getEtag(),
                        IndexJobRepository::SOURCE_BACKFILL,
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Media Embedding Service backfill folder scan failed', [
                'app' => 'media_embedding_connector',
                'exception_class' => $e::class,
            ]);
        }
    }
}
