<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AuditRepository
{
    public const TABLE = 'media_embed_audit';

    public function __construct(private IDBConnection $db)
    {
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public function record(
        ?string $actorUid,
        string $action,
        string $targetType,
        ?string $targetId,
        string $result,
        array $details = [],
    ): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'actor_uid' => $qb->createNamedParameter($actorUid),
            'action' => $qb->createNamedParameter($action),
            'target_type' => $qb->createNamedParameter($targetType),
            'target_id' => $qb->createNamedParameter($targetId),
            'result' => $qb->createNamedParameter($result),
            'details' => $qb->createNamedParameter(
                $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
            ),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->executeQuery();
        $rows = ResultCompat::fetchAllAssociative($result);
        $result->closeCursor();

        foreach ($rows as &$row) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            $row['details'] = is_array($details) ? $details : [];
        }
        unset($row);

        return $rows;
    }
}
