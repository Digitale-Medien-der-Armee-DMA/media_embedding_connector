<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Tests\Support\InMemoryIndexJobDatabase;
use PHPUnit\Framework\TestCase;

class IndexJobRepositoryTest extends TestCase
{
    public function testQueuedJobIsUpdatedWithLatestEvent(): void
    {
        $db = new InMemoryIndexJobDatabase([$this->job(1, IndexJobRepository::STATUS_QUEUED, 'old-etag')]);
        $repository = new IndexJobRepository($db);

        $jobId = $repository->enqueue('42', 'new-owner', 'new-etag', IndexJobRepository::ACTION_INDEX);

        self::assertSame(1, $jobId);
        self::assertCount(1, $db->rows);
        self::assertSame('new-owner', $db->rows[1]['owner_uid']);
        self::assertSame('new-etag', $db->rows[1]['etag']);
        self::assertSame(1, $db->lockCount);
        self::assertSame(1, $db->unlockCount);
    }

    public function testRunningJobCreatesQueuedFollowUp(): void
    {
        $db = new InMemoryIndexJobDatabase([$this->job(1, IndexJobRepository::STATUS_RUNNING, 'running-etag')]);
        $repository = new IndexJobRepository($db);

        $jobId = $repository->enqueue('42', 'new-owner', 'new-etag', IndexJobRepository::ACTION_INDEX);

        self::assertSame(2, $jobId);
        self::assertCount(2, $db->rows);
        self::assertSame('running-etag', $db->rows[1]['etag']);
        self::assertSame(IndexJobRepository::STATUS_RUNNING, $db->rows[1]['status']);
        self::assertSame('new-etag', $db->rows[2]['etag']);
        self::assertSame(IndexJobRepository::STATUS_QUEUED, $db->rows[2]['status']);
    }

    public function testFurtherEventUpdatesQueuedFollowUp(): void
    {
        $db = new InMemoryIndexJobDatabase([
            $this->job(1, IndexJobRepository::STATUS_RUNNING, 'running-etag'),
            $this->job(2, IndexJobRepository::STATUS_QUEUED, 'queued-etag'),
        ]);
        $repository = new IndexJobRepository($db);

        $jobId = $repository->enqueue('42', 'latest-owner', 'latest-etag', IndexJobRepository::ACTION_INDEX);

        self::assertSame(2, $jobId);
        self::assertCount(2, $db->rows);
        self::assertSame('running-etag', $db->rows[1]['etag']);
        self::assertSame('latest-owner', $db->rows[2]['owner_uid']);
        self::assertSame('latest-etag', $db->rows[2]['etag']);
    }

    public function testDeleteAndIndexJobsAreNotDeduplicatedTogether(): void
    {
        $db = new InMemoryIndexJobDatabase([
            $this->job(1, IndexJobRepository::STATUS_QUEUED, 'index-etag'),
        ]);
        $repository = new IndexJobRepository($db);

        $jobId = $repository->enqueue('42', null, null, IndexJobRepository::ACTION_DELETE);

        self::assertSame(2, $jobId);
        self::assertCount(2, $db->rows);
        self::assertSame(IndexJobRepository::ACTION_INDEX, $db->rows[1]['action']);
        self::assertSame(IndexJobRepository::ACTION_DELETE, $db->rows[2]['action']);
    }

    public function testImageBatchClaimPrioritizesInteractiveAndDoesNotMixBackfill(): void
    {
        $db = new InMemoryIndexJobDatabase([
            $this->job(1, IndexJobRepository::STATUS_QUEUED, 'backfill-etag', IndexJobRepository::ACTION_INDEX, IndexJobRepository::SOURCE_BACKFILL),
            $this->job(2, IndexJobRepository::STATUS_QUEUED, 'interactive-etag', IndexJobRepository::ACTION_INDEX, IndexJobRepository::SOURCE_INTERACTIVE),
            $this->job(3, IndexJobRepository::STATUS_QUEUED, 'delete-etag', IndexJobRepository::ACTION_DELETE, IndexJobRepository::SOURCE_INTERACTIVE),
        ]);
        $repository = new IndexJobRepository($db);

        $claimed = $repository->claimImageEmbeddingBatch(2, 8);

        self::assertSame([2], array_map(static fn (array $job): int => (int)$job['id'], $claimed));
        self::assertSame(IndexJobRepository::STATUS_QUEUED, $db->rows[1]['status']);
        self::assertSame(IndexJobRepository::STATUS_RUNNING, $db->rows[2]['status']);
        self::assertSame(IndexJobRepository::STATUS_QUEUED, $db->rows[3]['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function job(
        int $id,
        string $status,
        string $etag,
        string $action = IndexJobRepository::ACTION_INDEX,
        string $source = IndexJobRepository::SOURCE_INTERACTIVE,
    ): array
    {
        return [
            'id' => $id,
            'file_id' => '42',
            'owner_uid' => 'owner',
            'etag' => $etag,
            'action' => $action,
            'status' => $status,
            'job_source' => $source,
            'priority' => $source === IndexJobRepository::SOURCE_BACKFILL
                ? IndexJobRepository::PRIORITY_BACKFILL
                : IndexJobRepository::PRIORITY_INTERACTIVE,
            'attempts' => 0,
            'next_attempt_at' => time(),
            'claimed_at' => $status === IndexJobRepository::STATUS_RUNNING ? time() : null,
            'last_error' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];
    }
}
