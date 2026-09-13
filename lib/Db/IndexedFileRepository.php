<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class IndexedFileRepository
{
    public const TABLE = 'media_embed_idx_files';

    public function __construct(private IDBConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $file
     */
    public function upsert(array $file): void
    {
        $this->delete((string)$file['file_id']);
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'file_id' => $qb->createNamedParameter($file['file_id'], IQueryBuilder::PARAM_INT),
            'storage_id' => $qb->createNamedParameter((string)$file['storage_id']),
            'owner_uid' => $qb->createNamedParameter((string)$file['owner_uid']),
            'etag' => $qb->createNamedParameter((string)$file['etag']),
            'mime_type' => $qb->createNamedParameter((string)$file['mime_type']),
            'size_bytes' => $qb->createNamedParameter($file['size_bytes'], IQueryBuilder::PARAM_INT),
            'mtime' => $qb->createNamedParameter($file['mtime'], IQueryBuilder::PARAM_INT),
            'index_name' => $qb->createNamedParameter((string)$file['index_name']),
            'model_id' => $qb->createNamedParameter((string)($file['model_id'] ?? '')),
            'model_version' => $qb->createNamedParameter((string)($file['model_version'] ?? '')),
            'embedding_dim' => $qb->createNamedParameter((int)($file['embedding_dim'] ?? 0), IQueryBuilder::PARAM_INT),
            'contract_version' => $qb->createNamedParameter((string)($file['contract_version'] ?? '')),
            'model_fingerprint' => $qb->createNamedParameter((string)$file['model_fingerprint']),
            'indexed_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    public function delete(string $fileId): int
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $fileId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();

        return is_array($row) ? $row : null;
    }

    public function count(): int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectAlias($qb->func()->count('*'), 'file_count')
            ->from(self::TABLE)
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }
}
