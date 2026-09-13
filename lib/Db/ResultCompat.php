<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

/**
 * Uses the typed result APIs introduced in Nextcloud 33 while retaining the
 * legacy fallback required by supported Nextcloud 32 installations.
 */
final class ResultCompat
{
    /**
     * @return array<string, mixed>|false
     */
    public static function fetchAssociative(object $result): array|false
    {
        if (method_exists($result, 'fetchAssociative')) {
            $row = $result->fetchAssociative();
        } else {
            $row = $result->fetch();
        }

        return is_array($row) ? $row : false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchAllAssociative(object $result): array
    {
        if (method_exists($result, 'fetchAllAssociative')) {
            return $result->fetchAllAssociative();
        }

        return $result->fetchAll();
    }

    /**
     * @return list<mixed>
     */
    public static function fetchFirstColumn(object $result): array
    {
        if (method_exists($result, 'fetchFirstColumn')) {
            return $result->fetchFirstColumn();
        }

        return $result->fetchAll(\PDO::FETCH_COLUMN);
    }
}
