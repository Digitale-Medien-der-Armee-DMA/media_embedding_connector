<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SkipMarkerRepository
{
    public const REASON_IMAGE_TOO_LARGE = 'image_too_large';
    public const REASON_IMAGE_PIXEL_LIMIT_EXCEEDED = 'image_pixel_limit_exceeded';
    public const REASON_UNSUPPORTED_IMAGE_TYPE = 'unsupported_image_type';
    public const REASON_INVALID_IMAGE = 'invalid_image';
    public const TABLE = 'media_embed_skips';

    public function __construct(private IDBConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function put(string $fileId, string $etag, string $reason, array $contract): void
    {
        $this->resetFile($fileId);
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'etag' => $qb->createNamedParameter($etag),
            'reason' => $qb->createNamedParameter($reason),
            'model_id' => $qb->createNamedParameter($contract['model_id'] ?? null),
            'model_version' => $qb->createNamedParameter($contract['model_version'] ?? null),
            'model_fingerprint' => $qb->createNamedParameter($contract['model_fingerprint'] ?? null),
            'embedding_dim' => $qb->createNamedParameter(
                $contract['embedding_dim'] ?? null,
                IQueryBuilder::PARAM_INT,
            ),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    public function resetAll(): int
    {
        return $this->db->getQueryBuilder()->delete(self::TABLE)->executeStatement();
    }

    public function resetFile(string $fileId): int
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function resetByReason(string $reason): int
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('reason', $qb->createNamedParameter($reason)))
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('reason')
            ->selectAlias($qb->func()->count('*'), 'reason_count')
            ->from(self::TABLE)
            ->groupBy('reason')
            ->executeQuery();

        $total = 0;
        $byReason = [];
        foreach (ResultCompat::fetchAllAssociative($result) as $row) {
            $count = (int)$row['reason_count'];
            $byReason[(string)$row['reason']] = $count;
            $total += $count;
        }
        $result->closeCursor();

        return [
            'total' => $total,
            'by_reason' => $byReason,
            'reset_supported' => ['all', 'nextcloud_file_id', 'reason'],
        ];
    }
}
