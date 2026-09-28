<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\FileCacheScanRepository;
use OCA\MediaEmbeddingConnector\Db\IndexedFileRepository;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;
use OCA\MediaEmbeddingConnector\Db\StateRepository;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Walks all existing images once, in bounded slices, with a cursor that is
 * persisted after every page.
 *
 * Position: seen-user offset → scan roots of that user (home folder plus
 * mounted group or external storages; received shares are scanned through
 * their owner) → MIME type → last file id. Pausing, a failure, or a restart of
 * Nextcloud therefore resumes at the last completed page instead of at the
 * beginning. The scanner stops adding work while the backfill queue is above
 * its high-water mark, and it does not queue files whose stored index entry
 * or skip marker still matches their ETag and the active model.
 */
class BackfillScanner
{
    public const STATE_KEY = 'backfill_scan';
    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const PAGE_SIZE = 500;
    private const MIN_PAGE_SIZE = 50;
    private const MAX_CONSECUTIVE_ERRORS = 3;
    private const MAX_RECORDED_SKIPPED_ROOTS = 100;

    public function __construct(
        private StateRepository $state,
        private IndexLifecycleService $indexLifecycle,
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
        private FileCacheScanRepository $fileCache,
        private IndexJobRepository $jobs,
        private IndexedFileRepository $indexedFiles,
        private SkipMarkerRepository $skipMarkers,
        private IndexJobScheduler $scheduler,
        private ImageEligibilityService $eligibility,
        private AppAccessPolicy $accessPolicy,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $state = $this->state->getJson(self::STATE_KEY);
        return $state === [] ? self::freshState(self::STATUS_IDLE) : $state + self::freshState(self::STATUS_IDLE);
    }

    /**
     * Starts a scan. A running scan keeps its position unless $restart is set.
     *
     * @return array<string, mixed>
     */
    public function start(bool $restart = false): array
    {
        $current = $this->getState();
        if (!$restart && $current['status'] === self::STATUS_RUNNING) {
            return $current;
        }

        $state = self::freshState(self::STATUS_RUNNING);
        $state['started_at'] = time();
        $this->save($state);
        return $state;
    }

    /**
     * Advances the scan for at most $timeBudgetSeconds.
     *
     * @return array<string, mixed> the persisted state after this slice
     */
    public function runSlice(int $timeBudgetSeconds): array
    {
        $state = $this->getState();
        if (
            $state['status'] !== self::STATUS_RUNNING
            || !$this->config->isIndexingEnabled()
            || $this->indexLifecycle->isBackfillPaused()
        ) {
            return $state;
        }

        $deadline = microtime(true) + (float)max(1, $timeBudgetSeconds);
        $maxQueued = $this->config->getBackfillMaxQueued();
        while (microtime(true) < $deadline && $state['status'] === self::STATUS_RUNNING) {
            $queued = $this->jobs->countQueuedBackfill();
            $state['queued_backfill'] = $queued;
            if ($queued >= $maxQueued) {
                // Saving also refreshes updated_at, so a throttled scan does
                // not look stalled in the administration status.
                $state['throttled_at'] = time();
                $this->save($state);
                break;
            }

            $pageSize = max(self::MIN_PAGE_SIZE, min(self::PAGE_SIZE, $maxQueued - $queued));
            try {
                $state = $this->step($state, $pageSize);
                $state['error_count'] = 0;
                $state['throttled_at'] = null;
            } catch (\Throwable $e) {
                $state = $this->recordFailure($state, $e);
                $this->save($state);
                break;
            }
            $this->save($state);
        }

        return $state;
    }

    /**
     * Performs one unit of work: select the next user, resolve their scan
     * roots, or process one page of files.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function step(array $state, int $pageSize): array
    {
        if ($state['user_id'] === null) {
            $user = $this->nextUser((int)$state['user_offset']);
            if ($user === null) {
                $state['status'] = self::STATUS_COMPLETED;
                $state['completed_at'] = time();
                return $state;
            }
            $state['user_id'] = $user->getUID();
            $state['roots'] = null;
            if (!$this->accessPolicy->isUserAllowed($user)) {
                return $this->advanceUser($state);
            }
            return $state;
        }

        if ($state['roots'] === null) {
            $state['roots'] = $this->resolveRoots((string)$state['user_id']);
            $state['mime_types'] = $this->fileCache->resolveMimeTypeIds($this->eligibility->scanMimeTypes());
            $state['root_index'] = 0;
            $state['mime_index'] = 0;
            $state['file_cursor'] = 0;
            return $state;
        }

        $roots = (array)$state['roots'];
        $mimeIds = array_keys((array)$state['mime_types']);
        if ((int)$state['root_index'] >= count($roots)) {
            return $this->advanceUser($state);
        }
        if ((int)$state['mime_index'] >= count($mimeIds)) {
            return $this->advanceRoot($state);
        }

        $root = (array)$roots[(int)$state['root_index']];
        $mimeId = (int)$mimeIds[(int)$state['mime_index']];
        $rows = $this->fileCache->findFilesAfter(
            (int)$root['storage'],
            (string)$root['prefix'],
            $mimeId,
            (int)$state['file_cursor'],
            $pageSize,
        );
        $this->processPage((string)$state['user_id'], (string)$state['mime_types'][$mimeId], $rows, $state);

        if ($rows !== []) {
            $state['file_cursor'] = $rows[count($rows) - 1]['fileid'];
        }
        if (count($rows) < $pageSize) {
            $state['mime_index'] = (int)$state['mime_index'] + 1;
            $state['file_cursor'] = 0;
        }
        return $state;
    }

    /**
     * @param list<array{fileid: int, name: string, etag: string}> $rows
     * @param array<string, mixed> $state
     */
    private function processPage(string $userId, string $mimeType, array $rows, array &$state): void
    {
        if ($rows === []) {
            return;
        }

        $fileIds = array_map(static fn (array $row): string => (string)$row['fileid'], $rows);
        $indexed = $this->indexedFiles->findByFileIds($fileIds);
        $skips = $this->skipMarkers->findByFileIds($fileIds);
        $lifecycle = $this->indexLifecycle->getStatus();
        $fingerprint = (string)($lifecycle['active_contract']['model_fingerprint'] ?? '');
        $writeIndex = (string)($lifecycle['write_index'] ?? '');

        foreach ($rows as $row) {
            $fileId = (string)$row['fileid'];
            $state['counters']['scanned'] = (int)$state['counters']['scanned'] + 1;
            if (!$this->eligibility->isIndexingCandidate($mimeType, $row['name'])) {
                continue;
            }
            if (IndexFreshness::isCurrent($indexed[$fileId] ?? null, $skips[$fileId] ?? null, $row['etag'], $fingerprint, $writeIndex)) {
                $state['counters']['unchanged'] = (int)$state['counters']['unchanged'] + 1;
                continue;
            }
            $this->scheduler->enqueueIndex($fileId, $userId, $row['etag'], IndexJobRepository::SOURCE_BACKFILL);
            $state['counters']['queued'] = (int)$state['counters']['queued'] + 1;
        }
    }

    /**
     * @return list<array{storage: int, prefix: string}>
     */
    private function resolveRoots(string $userId): array
    {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $rootIds = [$userFolder->getId()];
        foreach ($this->rootFolder->getMountsIn($userFolder->getPath()) as $mount) {
            // Received shares are scanned once through their owner.
            if ($mount->getMountType() === 'shared') {
                continue;
            }
            $rootId = (int)$mount->getStorageRootId();
            if ($rootId > 0) {
                $rootIds[] = $rootId;
            }
        }

        $roots = [];
        foreach (array_unique($rootIds) as $rootId) {
            $root = $this->fileCache->findScanRoot((int)$rootId);
            if ($root !== null && !in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }
        return $roots;
    }

    private function nextUser(int $offset): ?IUser
    {
        foreach ($this->userManager->getSeenUsers($offset, 1) as $user) {
            return $user;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function advanceUser(array $state): array
    {
        $state['user_offset'] = (int)$state['user_offset'] + 1;
        $state['user_id'] = null;
        $state['roots'] = null;
        $state['mime_types'] = [];
        $state['root_index'] = 0;
        $state['mime_index'] = 0;
        $state['file_cursor'] = 0;
        $state['counters']['users_completed'] = (int)$state['counters']['users_completed'] + 1;
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function advanceRoot(array $state): array
    {
        $state['root_index'] = (int)$state['root_index'] + 1;
        $state['mime_index'] = 0;
        $state['file_cursor'] = 0;
        return $state;
    }

    /**
     * Keeps the position so the next slice retries the same page. After
     * repeated failures at one position the current scan root is skipped and
     * recorded, so a single broken storage cannot stop the whole backfill.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function recordFailure(array $state, \Throwable $e): array
    {
        $state['error_count'] = (int)$state['error_count'] + 1;
        $state['last_error'] = [
            'at' => time(),
            'user_id' => $state['user_id'],
            'root_index' => $state['root_index'],
            'file_cursor' => $state['file_cursor'],
            'exception_class' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, 500),
        ];
        $this->logger->error('Media Embedding Service backfill scan step failed', [
            'app' => 'media_embedding_connector',
            'user_id' => $state['user_id'],
            'root_index' => $state['root_index'],
            'mime_index' => $state['mime_index'],
            'file_cursor' => $state['file_cursor'],
            'attempt' => $state['error_count'],
            'exception' => $e,
        ]);

        if ((int)$state['error_count'] < self::MAX_CONSECUTIVE_ERRORS) {
            return $state;
        }

        $rootsResolved = is_array($state['roots'] ?? null);

        if (count((array)$state['skipped_roots']) < self::MAX_RECORDED_SKIPPED_ROOTS) {
            $state['skipped_roots'][] = [
                'user_id' => $state['user_id'],
                'root' => $rootsResolved ? ($state['roots'][(int)$state['root_index']] ?? null) : null,
                'exception_class' => $e::class,
                'at' => time(),
            ];
        }
        $state['counters']['roots_skipped'] = (int)$state['counters']['roots_skipped'] + 1;
        $state['error_count'] = 0;
        return $rootsResolved ? $this->advanceRoot($state) : $this->advanceUser($state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        $state['updated_at'] = time();
        $this->state->setJson(self::STATE_KEY, $state);
    }

    /**
     * @return array<string, mixed>
     */
    private static function freshState(string $status): array
    {
        return [
            'status' => $status,
            'started_at' => null,
            'updated_at' => null,
            'completed_at' => null,
            'throttled_at' => null,
            'queued_backfill' => null,
            'user_offset' => 0,
            'user_id' => null,
            'roots' => null,
            'mime_types' => [],
            'root_index' => 0,
            'mime_index' => 0,
            'file_cursor' => 0,
            'error_count' => 0,
            'last_error' => null,
            'skipped_roots' => [],
            'counters' => [
                'scanned' => 0,
                'queued' => 0,
                'unchanged' => 0,
                'users_completed' => 0,
                'roots_skipped' => 0,
            ],
        ];
    }
}
