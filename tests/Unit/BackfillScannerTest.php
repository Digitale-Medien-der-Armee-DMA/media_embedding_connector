<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\FileCacheScanRepository;
use OCA\MediaEmbeddingConnector\Db\IndexedFileRepository;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;
use OCA\MediaEmbeddingConnector\Db\StateRepository;
use OCA\MediaEmbeddingConnector\Service\AppAccessPolicy;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\BackfillScanner;
use OCA\MediaEmbeddingConnector\Service\ImageEligibilityService;
use OCA\MediaEmbeddingConnector\Service\IndexJobScheduler;
use OCA\MediaEmbeddingConnector\Service\IndexLifecycleService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackfillScannerTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $stateStore = [];
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $enqueued = [];
    /** @var list<array{0: int, 1: string, 2: int, 3: int, 4: int}> */
    private array $pageCalls = [];
    /** @var array<string, list<array{fileid: int, name: string, etag: string}>> files per "storage:prefix" */
    private array $files = [];
    private int $queuedBackfill = 0;
    private bool $paused = false;
    private ?\Throwable $pageFailure = null;

    private FileCacheScanRepository&MockObject $fileCache;
    private IndexedFileRepository&MockObject $indexedFiles;
    private LoggerInterface&MockObject $logger;
    private BackfillScanner $scanner;

    protected function setUp(): void
    {
        $state = $this->createMock(StateRepository::class);
        $state->method('getJson')->willReturnCallback(fn (string $key): array => $this->stateStore[$key] ?? []);
        $state->method('setJson')->willReturnCallback(function (string $key, array $value): void {
            // Round-trip through JSON like the real repository does.
            $this->stateStore[$key] = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true);
        });

        $lifecycle = $this->createMock(IndexLifecycleService::class);
        $lifecycle->method('isBackfillPaused')->willReturnCallback(fn (): bool => $this->paused);
        $lifecycle->method('getStatus')->willReturn([
            'active_contract' => ['model_fingerprint' => 'fp'],
            'write_index' => 'idx',
        ]);

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $users = $this->createMock(IUserManager::class);
        $users->method('getSeenUsers')->willReturnCallback(
            static fn (int $offset, ?int $limit): \Iterator => new \ArrayIterator(array_slice([$alice], $offset, $limit)),
        );

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getId')->willReturn(10);
        $userFolder->method('getPath')->willReturn('/alice/files');
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
        $rootFolder->method('getMountsIn')->with('/alice/files')->willReturn([
            $this->mount('group', 20),
            $this->mount('shared', 30),
        ]);

        $this->fileCache = $this->createMock(FileCacheScanRepository::class);
        $this->fileCache->method('resolveMimeTypeIds')->willReturn([3 => 'image/jpeg']);
        $this->fileCache->method('findScanRoot')->willReturnCallback(static fn (int $id): ?array => match ($id) {
            10 => ['storage' => 1, 'prefix' => 'files/'],
            20 => ['storage' => 2, 'prefix' => '__groupfolders/1/'],
            default => self::fail('Received shares must not be resolved as scan roots.'),
        });
        $this->fileCache->method('findFilesAfter')->willReturnCallback(
            function (int $storage, string $prefix, int $mimeId, int $after, int $limit): array {
                $this->pageCalls[] = [$storage, $prefix, $mimeId, $after, $limit];
                if ($this->pageFailure !== null) {
                    throw $this->pageFailure;
                }
                $rows = array_values(array_filter(
                    $this->files[$storage . ':' . $prefix] ?? [],
                    static fn (array $row): bool => $row['fileid'] > $after,
                ));
                return array_slice($rows, 0, $limit);
            },
        );

        $jobs = $this->createMock(IndexJobRepository::class);
        $jobs->method('countQueuedBackfill')->willReturnCallback(fn (): int => $this->queuedBackfill);

        $this->indexedFiles = $this->createMock(IndexedFileRepository::class);
        $skipMarkers = $this->createMock(SkipMarkerRepository::class);
        $skipMarkers->method('findByFileIds')->willReturn([]);

        $scheduler = $this->createMock(IndexJobScheduler::class);
        $scheduler->method('enqueueIndex')->willReturnCallback(
            function (string $fileId, ?string $owner, ?string $etag, string $source): int {
                self::assertSame(IndexJobRepository::SOURCE_BACKFILL, $source);
                $this->enqueued[] = [$fileId, (string)$owner, (string)$etag];
                ++$this->queuedBackfill;
                return count($this->enqueued);
            },
        );

        $access = $this->createMock(AppAccessPolicy::class);
        $access->method('isUserAllowed')->willReturn(true);

        $config = $this->createMock(AppConfig::class);
        $config->method('isIndexingEnabled')->willReturn(true);
        $config->method('getBackfillMaxQueued')->willReturn(10_000);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->scanner = new BackfillScanner(
            $state,
            $lifecycle,
            $users,
            $rootFolder,
            $this->fileCache,
            $jobs,
            $this->indexedFiles,
            $skipMarkers,
            $scheduler,
            new ImageEligibilityService(),
            $access,
            $config,
            $this->logger,
        );
    }

    public function testFullScanQueuesChangedImagesOnceAndCompletes(): void
    {
        $this->files['1:files/'] = [
            ['fileid' => 100, 'name' => 'unchanged.jpg', 'etag' => 'e100'],
            ['fileid' => 101, 'name' => 'new.jpg', 'etag' => 'e101'],
            ['fileid' => 102, 'name' => 'modified.jpg', 'etag' => 'e102-new'],
        ];
        $this->files['2:__groupfolders/1/'] = [
            ['fileid' => 200, 'name' => 'group.jpg', 'etag' => 'e200'],
        ];
        $this->indexedFiles->method('findByFileIds')->willReturn([
            '100' => ['etag' => 'e100', 'model_fingerprint' => 'fp', 'index_name' => 'idx'],
            '102' => ['etag' => 'e102-old', 'model_fingerprint' => 'fp', 'index_name' => 'idx'],
        ]);

        $this->scanner->start();
        $state = $this->scanner->runSlice(30);

        self::assertSame(BackfillScanner::STATUS_COMPLETED, $state['status']);
        self::assertSame([
            ['101', 'alice', 'e101'],
            ['102', 'alice', 'e102-new'],
            ['200', 'alice', 'e200'],
        ], $this->enqueued);
        self::assertSame(4, $state['counters']['scanned']);
        self::assertSame(1, $state['counters']['unchanged']);
        self::assertSame(3, $state['counters']['queued']);
        self::assertSame(1, $state['counters']['users_completed']);
    }

    public function testScanStopsAtHighWaterMarkWithoutTouchingCursor(): void
    {
        $this->files['1:files/'] = [['fileid' => 100, 'name' => 'a.jpg', 'etag' => 'e']];
        $this->queuedBackfill = 10_000;

        $this->scanner->start();
        $state = $this->scanner->runSlice(30);

        self::assertSame(BackfillScanner::STATUS_RUNNING, $state['status']);
        self::assertNotNull($state['throttled_at']);
        self::assertSame([], $this->pageCalls);
        self::assertSame([], $this->enqueued);
        self::assertNotNull($this->stateStore[BackfillScanner::STATE_KEY]['throttled_at'], 'Throttling is persisted.');

        $this->indexedFiles->method('findByFileIds')->willReturn([]);
        $this->queuedBackfill = 0;
        $state = $this->scanner->runSlice(30);
        self::assertNull($state['throttled_at']);
        self::assertSame([['100', 'alice', 'e']], $this->enqueued);
    }

    public function testPageSizeShrinksToRemainingQueueCapacity(): void
    {
        $this->files['1:files/'] = [['fileid' => 100, 'name' => 'a.jpg', 'etag' => 'e']];
        $this->indexedFiles->method('findByFileIds')->willReturn([]);
        $this->queuedBackfill = 9_880;

        $this->scanner->start();
        $this->scanner->runSlice(30);

        self::assertSame(120, $this->pageCalls[0][4]);
    }

    public function testPausedScanDoesNothingAndResumesAtStoredCursor(): void
    {
        $this->files['1:files/'] = [
            ['fileid' => 100, 'name' => 'a.jpg', 'etag' => 'e100'],
            ['fileid' => 101, 'name' => 'b.jpg', 'etag' => 'e101'],
        ];
        $this->indexedFiles->method('findByFileIds')->willReturn([]);
        $this->scanner->start();
        $stored = $this->stateStore[BackfillScanner::STATE_KEY];
        $this->stateStore[BackfillScanner::STATE_KEY] = [
            'user_id' => 'alice',
            'roots' => [['storage' => 1, 'prefix' => 'files/']],
            'mime_types' => [3 => 'image/jpeg'],
            'file_cursor' => 100,
        ] + $stored;

        $this->paused = true;
        $this->scanner->runSlice(30);
        self::assertSame([], $this->pageCalls);

        $this->paused = false;
        $this->scanner->runSlice(30);
        self::assertSame([1, 'files/', 3, 100, 500], $this->pageCalls[0]);
        self::assertSame([['101', 'alice', 'e101']], $this->enqueued);
    }

    public function testStartKeepsRunningScanUnlessRestartIsRequested(): void
    {
        $this->scanner->start();
        $this->stateStore[BackfillScanner::STATE_KEY]['user_offset'] = 7;

        self::assertSame(7, $this->scanner->start()['user_offset']);
        self::assertSame(0, $this->scanner->start(true)['user_offset']);
    }

    public function testFailingPageKeepsPositionThenSkipsRootAfterRepeatedFailures(): void
    {
        $this->files['2:__groupfolders/1/'] = [['fileid' => 200, 'name' => 'g.jpg', 'etag' => 'e200']];
        $this->indexedFiles->method('findByFileIds')->willReturn([]);
        $this->pageFailure = new \RuntimeException('storage unavailable');
        $this->logger->expects(self::exactly(3))
            ->method('error')
            ->with(self::anything(), self::callback(static fn (array $context): bool => $context['exception'] instanceof \RuntimeException));

        $this->scanner->start();
        $state = $this->scanner->runSlice(30);
        self::assertSame(1, $state['error_count']);
        self::assertSame(0, $state['root_index']);
        self::assertSame(\RuntimeException::class, $state['last_error']['exception_class']);

        $this->scanner->runSlice(30);
        $state = $this->scanner->runSlice(30);
        self::assertSame(1, $state['root_index'], 'The broken root is skipped after three failures.');
        self::assertSame(1, $state['counters']['roots_skipped']);
        self::assertSame('alice', $state['skipped_roots'][0]['user_id']);

        $this->pageFailure = null;
        $state = $this->scanner->runSlice(30);
        self::assertSame(BackfillScanner::STATUS_COMPLETED, $state['status']);
        self::assertSame([['200', 'alice', 'e200']], $this->enqueued);
    }

    private function mount(string $type, int $rootId): IMountPoint
    {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountType')->willReturn($type);
        $mount->method('getStorageRootId')->willReturn($rootId);
        return $mount;
    }
}
