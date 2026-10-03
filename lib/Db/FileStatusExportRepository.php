<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class FileStatusExportRepository
{
    public const STATUSES = ['queued', 'running', 'failed', 'skipped'];

    public function __construct(private IDBConnection $db) {}

    /** @return \Generator<int, array<string, mixed>> */
    public function rows(string $status): \Generator
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid export status.');
        }
        $table = $status === 'skipped' ? SkipMarkerRepository::TABLE : IndexJobRepository::TABLE;
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)->orderBy('id', 'DESC')->setMaxResults(1);
        $result = $qb->executeQuery();
        $upperRow = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        $upperId = is_array($upperRow) ? (int)$upperRow['id'] : 0;
        $after = 0;
        while ($after < $upperId) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('r.*')
                ->selectAlias('f.name', 'file_name')
                ->selectAlias('f.path', 'storage_path')
                ->selectAlias('f.storage', 'storage_numeric_id')
                ->from($table, 'r')
                ->leftJoin('r', 'filecache', 'f', $qb->expr()->eq('r.file_id', 'f.fileid'))
                ->where($qb->expr()->gt('r.id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->lte('r.id', $qb->createNamedParameter($upperId, IQueryBuilder::PARAM_INT)))
                ->orderBy('r.id', 'ASC')->setMaxResults(1000);
            if ($status !== 'skipped') {
                $qb->andWhere($qb->expr()->eq('r.status', $qb->createNamedParameter($status)));
            }
            $result = $qb->executeQuery();
            $rows = ResultCompat::fetchAllAssociative($result);
            $result->closeCursor();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $after = (int)$row['id'];
                yield $row;
            }
        }
    }
}
