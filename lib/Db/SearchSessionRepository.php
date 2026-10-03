<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Stores only a bounded ranked list of IDs/scores, never query images or vectors. */
class SearchSessionRepository
{
    public const TABLE = 'media_embed_search';
    public const TTL = 900;

    public function __construct(private IDBConnection $db, private ITimeFactory $time) {}

    /** @param array<string, mixed> $payload
     * @return array{session_id:string, expires_at:int}
     */
    public function create(string $userId, array $payload): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = $this->time->getTime() + self::TTL;
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'token_hash' => $qb->createNamedParameter(hash('sha256', $token)),
            'user_id' => $qb->createNamedParameter($userId),
            'expires_at' => $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT),
            'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
        ])->executeStatement();
        return ['session_id' => $token, 'expires_at' => $expires];
    }

    /** @return array<string, mixed> */
    public function load(string $userId, string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw $this->expired();
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('payload', 'expires_at')->from(self::TABLE)
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter(hash('sha256', $token))))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        if ($row === false) {
            throw $this->expired();
        }
        $payload = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw $this->expired();
        }
        return $payload + ['session_id' => $token, 'expires_at' => (int)$row['expires_at']];
    }

    public function purgeExpired(): int
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function expired(): ExternalServiceException
    {
        return new ExternalServiceException('Search session expired or is unavailable. Start a new search.', 'search_session_expired');
    }
}
