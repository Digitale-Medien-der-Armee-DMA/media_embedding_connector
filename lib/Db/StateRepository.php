<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class StateRepository
{
    public const TABLE = 'media_embed_state';

    public function __construct(private IDBConnection $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('state_value')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('state_key', $qb->createNamedParameter($key)))
            ->setMaxResults(1)
            ->executeQuery();
        $value = $result->fetchOne();
        $result->closeCursor();

        return $value === false ? $default : (string)$value;
    }

    public function set(string $key, string $value): void
    {
        $now = time();
        $updated = $this->update($key, $value, $now);
        if ($updated > 0) {
            return;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::TABLE)->values([
                'state_key' => $qb->createNamedParameter($key),
                'state_value' => $qb->createNamedParameter($value),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        } catch (\Throwable) {
            $this->update($key, $value, $now);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $key, array $default = []): array
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setJson(string $key, array $value): void
    {
        $this->set($key, json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function update(string $key, string $value, int $now): int
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('state_value', $qb->createNamedParameter($value))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('state_key', $qb->createNamedParameter($key)))
            ->executeStatement();
    }
}
