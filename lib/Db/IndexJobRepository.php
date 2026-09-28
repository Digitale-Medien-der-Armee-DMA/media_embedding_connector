<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class IndexJobRepository
{
    public const TABLE = 'media_embed_idx_jobs';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const ACTION_INDEX = 'index';
    public const ACTION_DELETE = 'delete';
    public const SOURCE_INTERACTIVE = 'interactive';
    public const SOURCE_BACKFILL = 'backfill';
    public const PRIORITY_INTERACTIVE = 10;
    public const PRIORITY_BACKFILL = 100;

    public function __construct(private IDBConnection $db)
    {
    }

    /**
     * Queues a file without a table lock. A concurrent enqueue of the same
     * file can at worst create a second queued row; processing is idempotent,
     * so such a duplicate only costs one additional embedding request.
     */
    public function enqueue(
        string $fileId,
        ?string $ownerUid,
        ?string $etag,
        string $action,
        string $source = self::SOURCE_INTERACTIVE,
    ): int
    {
        $now = time();
        $priority = self::priorityForSource($source);
        $existing = $this->findQueued($fileId, $action);
        if ($existing !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('owner_uid', $qb->createNamedParameter($ownerUid))
                ->set('etag', $qb->createNamedParameter($etag))
                ->set('job_source', $qb->createNamedParameter($source))
                ->set('priority', $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT))
                ->set('next_attempt_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing['id'], IQueryBuilder::PARAM_INT)))
                ->executeStatement();
            return (int)$existing['id'];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'owner_uid' => $qb->createNamedParameter($ownerUid),
            'etag' => $qb->createNamedParameter($etag),
            'action' => $qb->createNamedParameter($action),
            'status' => $qb->createNamedParameter(self::STATUS_QUEUED),
            'job_source' => $qb->createNamedParameter($source),
            'priority' => $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT),
            'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'next_attempt_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'claimed_at' => $qb->createNamedParameter(null),
            'last_error' => $qb->createNamedParameter(null),
            'last_status_code' => $qb->createNamedParameter(null),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        return $qb->getLastInsertId();
    }

    /**
     * Claims up to $limit queued image jobs of one lane without a table lock.
     * Each row is claimed with a conditional update, so concurrent workers
     * never receive the same job. The running-item limit is a soft limit:
     * workers racing for the last free slots may exceed it by one batch.
     *
     * @return list<array<string, mixed>>
     */
    public function claimImageEmbeddingBatch(
        int $limit,
        int $runningItemLimit,
        int $staleAfterSeconds = 1800,
    ): array {
        $now = time();
        $limit = max(1, min(64, $limit));
        $this->recoverStale($now - $staleAfterSeconds);
        $running = $this->countRunningByAction(self::ACTION_INDEX);
        $availableSlots = max(0, $runningItemLimit - $running);
        if ($availableSlots <= 0) {
            return [];
        }

        $claimLimit = min($limit, $availableSlots);
        $first = $this->selectNextQueuedImageJob($now);
        if ($first === null) {
            return [];
        }
        $source = (string)($first['job_source'] ?? self::SOURCE_INTERACTIVE);

        $select = $this->db->getQueryBuilder();
        $result = $select->select('*')
            ->from(self::TABLE)
            ->where($select->expr()->eq('status', $select->createNamedParameter(self::STATUS_QUEUED)))
            ->andWhere($select->expr()->eq('action', $select->createNamedParameter(self::ACTION_INDEX)))
            ->andWhere($select->expr()->eq('job_source', $select->createNamedParameter($source)))
            ->andWhere($select->expr()->lte('next_attempt_at', $select->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($claimLimit)
            ->executeQuery();
        $candidates = ResultCompat::fetchAllAssociative($result);
        $result->closeCursor();

        $claimed = [];
        foreach ($candidates as $job) {
            if ($this->claimQueuedRow((int)$job['id'], $now)) {
                $job['status'] = self::STATUS_RUNNING;
                $job['claimed_at'] = $now;
                $job['updated_at'] = $now;
                $claimed[] = $job;
            }
        }
        return $claimed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectNextQueuedImageJob(int $now): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
            ->andWhere($qb->expr()->eq('action', $qb->createNamedParameter(self::ACTION_INDEX)))
            ->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->orderBy('priority', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

    private function claimQueuedRow(int $jobId, int $now): bool
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter(self::STATUS_RUNNING))
            ->set('claimed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
            ->andWhere($qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeStatement() === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claim(int $jobId, int $concurrencyLimit, int $staleAfterSeconds = 1800): ?array
    {
        $now = time();
        $this->recoverStale($now - $staleAfterSeconds);
        if ($this->countByStatus(self::STATUS_RUNNING) >= $concurrencyLimit) {
            return null;
        }

        return $this->claimQueuedRow($jobId, $now) ? $this->findById($jobId) : null;
    }

    /**
     * Number of backfill image jobs still waiting. The scanner stops adding
     * work while this is above its high-water mark.
     */
    public function countQueuedBackfill(): int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectAlias($qb->func()->count('*'), 'status_count')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
            ->andWhere($qb->expr()->eq('action', $qb->createNamedParameter(self::ACTION_INDEX)))
            ->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter(self::PRIORITY_BACKFILL, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Deletes indexed and skipped job rows that finished before $finishedBefore.
     * Completion stores the finish time in next_attempt_at, which is covered by
     * the (status, next_attempt_at) index. Deletes in chunks to keep each
     * statement short.
     */
    public function purgeCompleted(int $finishedBefore, int $chunkSize = 5000, int $maxChunks = 100): int
    {
        $deleted = 0;
        for ($chunk = 0; $chunk < $maxChunks; ++$chunk) {
            $select = $this->db->getQueryBuilder();
            $result = $select->select('id')
                ->from(self::TABLE)
                ->where($select->expr()->in('status', $select->createNamedParameter(
                    [self::STATUS_INDEXED, self::STATUS_SKIPPED],
                    IQueryBuilder::PARAM_STR_ARRAY,
                )))
                ->andWhere($select->expr()->lt('next_attempt_at', $select->createNamedParameter($finishedBefore, IQueryBuilder::PARAM_INT)))
                ->setMaxResults($chunkSize)
                ->executeQuery();
            $ids = array_map('intval', ResultCompat::fetchFirstColumn($result));
            $result->closeCursor();
            if ($ids === []) {
                break;
            }

            $delete = $this->db->getQueryBuilder();
            $deleted += $delete->delete(self::TABLE)
                ->where($delete->expr()->in('id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
            if (count($ids) < $chunkSize) {
                break;
            }
        }
        return $deleted;
    }

    public function markComplete(int $jobId, string $status): void
    {
        $this->updateStatus($jobId, $status, null, null);
    }

    public function markRetry(
        int $jobId,
        int $attempts,
        int $nextAttemptAt,
        string $error,
        ?int $statusCode = null,
    ): void
    {
        $qb = $this->db->getQueryBuilder();
        $statusCodeParam = $statusCode === null
            ? $qb->createNamedParameter(null)
            : $qb->createNamedParameter($statusCode, IQueryBuilder::PARAM_INT);
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
            ->set('attempts', $qb->createNamedParameter($attempts, IQueryBuilder::PARAM_INT))
            ->set('next_attempt_at', $qb->createNamedParameter($nextAttemptAt, IQueryBuilder::PARAM_INT))
            ->set('claimed_at', $qb->createNamedParameter(null))
            ->set('last_error', $qb->createNamedParameter(substr($error, 0, 255)))
            ->set('last_status_code', $statusCodeParam)
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function markFailed(int $jobId, string $error, ?int $statusCode = null): void
    {
        $this->updateStatus($jobId, self::STATUS_FAILED, substr($error, 0, 255), null, $statusCode);
    }

    /**
     * @return list<int>
     */
    public function retryFailed(?string $errorCode = null): array
    {
        $select = $this->db->getQueryBuilder();
        $select->select('id')
            ->from(self::TABLE)
            ->where($select->expr()->eq('status', $select->createNamedParameter(self::STATUS_FAILED)));
        if ($errorCode !== null && $errorCode !== '') {
            $select->andWhere($select->expr()->eq('last_error', $select->createNamedParameter($errorCode)));
        }
        $result = $select->executeQuery();
        $ids = array_map('intval', ResultCompat::fetchFirstColumn($result));
        $result->closeCursor();

        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
            ->set('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('next_attempt_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->set('claimed_at', $qb->createNamedParameter(null))
            ->set('last_status_code', $qb->createNamedParameter(null))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_FAILED)));
        if ($errorCode !== null && $errorCode !== '') {
            $qb->andWhere($qb->expr()->eq('last_error', $qb->createNamedParameter($errorCode)));
        }
        $qb->executeStatement();
        return $ids;
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('status')
            ->selectAlias($qb->func()->count('*'), 'status_count')
            ->from(self::TABLE)
            ->groupBy('status')
            ->executeQuery();
        $stats = [
            self::STATUS_QUEUED => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_INDEXED => 0,
            self::STATUS_SKIPPED => 0,
            self::STATUS_FAILED => 0,
        ];
        foreach (ResultCompat::fetchAllAssociative($result) as $row) {
            $stats[(string)$row['status']] = (int)$row['status_count'];
        }
        $result->closeCursor();
        return $stats;
    }

    /**
     * @return list<int>
     */
    public function getQueuedIds(int $limit = 1000, ?string $action = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(max(1, min(10_000, $limit)));
        if ($action !== null) {
            $qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
        }
        $result = $qb->executeQuery();
        $ids = array_map('intval', ResultCompat::fetchFirstColumn($result));
        $result->closeCursor();
        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $jobId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQueued(string $fileId, string $action): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_QUEUED)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

    private function countByStatus(string $status): int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectAlias($qb->func()->count('*'), 'status_count')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    private function countRunningByAction(string $action): int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectAlias($qb->func()->count('*'), 'status_count')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING)))
            ->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    private function recoverStale(int $staleBefore): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter(self::STATUS_QUEUED))
            ->set('claimed_at', $qb->createNamedParameter(null))
            ->set('next_attempt_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_RUNNING)))
            ->andWhere($qb->expr()->lt('claimed_at', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function updateStatus(
        int $jobId,
        string $status,
        ?string $error,
        ?int $nextAttemptAt,
        ?int $statusCode = null,
    ): void {
        $qb = $this->db->getQueryBuilder();
        $statusCodeParam = $statusCode === null
            ? $qb->createNamedParameter(null)
            : $qb->createNamedParameter($statusCode, IQueryBuilder::PARAM_INT);
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter($status))
            ->set('claimed_at', $qb->createNamedParameter(null))
            ->set('last_error', $qb->createNamedParameter($error))
            ->set('last_status_code', $statusCodeParam)
            ->set('next_attempt_at', $qb->createNamedParameter($nextAttemptAt ?? time(), IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private static function priorityForSource(string $source): int
    {
        return $source === self::SOURCE_BACKFILL ? self::PRIORITY_BACKFILL : self::PRIORITY_INTERACTIVE;
    }
}
