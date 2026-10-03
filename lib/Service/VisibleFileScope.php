<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Db\VisibleFileCandidateRepository;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

class VisibleFileScope
{
    private const PAGE_SIZE = 1000;
    private const FILTER_SIZE = 50000;

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private ImageEligibilityService $eligibility,
        private VisibleFileCandidateRepository $candidates,
    ) {}

    /**
     * Enumerate the user's mounted filesystem, including received shares and
     * permission-wrapped group/external folders. Never cap the complete scope
     * or cache it across requests: grants and revocations must take effect.
     *
     * @return \Generator<int, list<string>>
     */
    public function batches(string $userId): \Generator
    {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            throw new ExternalServiceException('Search user is unavailable.', 'search_scope_unavailable');
        }
        $folder = $this->rootFolder->getUserFolder($userId);
        $roots = [(int)$folder->getId()];
        foreach ($this->rootFolder->getMountsIn($folder->getPath()) as $mount) {
            $rootId = $mount->getStorageRootId();
            if ($rootId <= 0) {
                throw new ExternalServiceException('Mounted storage is unavailable.', 'search_scope_unavailable');
            }
            $roots[] = $rootId;
        }
        $batch = [];
        // Errors must abort search, never silently return an incomplete scope.
        foreach ($this->candidates->batches($roots, self::PAGE_SIZE) as $candidateIds) {
            $nodes = $folder->search(new FileScopeQuery($candidateIds, $user));
            $pageIds = [];
            foreach ($nodes as $node) {
                $id = (int)$node->getId();
                // A file can have several mounts with different permissions.
                if ($node instanceof File && $node->isReadable()
                    && $this->eligibility->isIndexingCandidate($node->getMimeType(), $node->getName())) {
                    $pageIds[(string)$id] = (string)$id;
                }
            }
            foreach ($pageIds as $id) {
                $batch[] = $id;
                if (count($batch) === self::FILTER_SIZE) {
                    yield $batch;
                    $batch = [];
                }
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
        return;
    }
}
