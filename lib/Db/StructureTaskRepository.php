<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class StructureTaskRepository
{
    public const TABLE = 'media_embed_structure';
    public const ERRORS = 'media_embed_struct_err';
    public function __construct(private IDBConnection $db) {}

    /** @param list<int>|null $impactRoots */
    public function enqueue(int $rootId, string $index, bool $delete = false, ?array $impactRoots = null): void
    {
        // Separate tasks preserve a second mutation arriving during a repair.
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'root_id' => $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT),
            'impact_roots' => $qb->createNamedParameter($impactRoots === null ? null : json_encode($impactRoots, JSON_THROW_ON_ERROR)),
            'delete_subtree' => $qb->createNamedParameter($delete, IQueryBuilder::PARAM_BOOL),
            'index_name' => $qb->createNamedParameter($index),
            'cursor_value' => $qb->createNamedParameter(''),
        ])->executeStatement();
    }

    /** @return array<string, mixed>|null */
    public function next(): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')->from(self::TABLE)->orderBy('id', 'ASC')->setMaxResults(1)->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function pending(string $index): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('root_id', 'impact_roots', 'delete_subtree')->from(self::TABLE)
            ->where($qb->expr()->eq('index_name', $qb->createNamedParameter($index)))->executeQuery();
        $rows = ResultCompat::fetchAllAssociative($result);
        $result->closeCursor();
        return $rows;
    }

    public function advance(int $id, ?string $cursor): void
    {
        $qb = $this->db->getQueryBuilder();
        if ($cursor === null) { $qb->delete(self::TABLE); }
        else { $qb->update(self::TABLE)->set('cursor_value', $qb->createNamedParameter($cursor)); }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    public function error(int $id, string $error, ?string $path, string $index = ''): void
    {
        $this->clearError($id, $index);
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::ERRORS)->values([
            'file_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
            'index_name' => $qb->createNamedParameter($index),
            'last_error' => $qb->createNamedParameter(substr($error, 0, 255)),
            'storage_path' => $qb->createNamedParameter($path),
        ])->executeStatement();
    }

    public function clearError(int $id, string $index = ''): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::ERRORS)->where($qb->expr()->eq('file_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('index_name', $qb->createNamedParameter($index)))->executeStatement();
    }

    public function resetErrors(): void
    {
        $this->db->getQueryBuilder()->delete(self::ERRORS)->executeStatement();
    }

    public function hasErrors(): bool
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('file_id')->from(self::ERRORS)->setMaxResults(1)->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function errors(): \Generator
    {
        $after = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'file_id', 'last_error', 'storage_path')->from(self::ERRORS)
                ->where($qb->expr()->gt('id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')->setMaxResults(1000)->executeQuery();
            $rows = ResultCompat::fetchAllAssociative($result);
            $result->closeCursor();
            foreach ($rows as $row) {
                $after = (int)$row['id'];
                yield ['id' => (int)$row['file_id'], 'last_error' => $row['last_error'], 'storage_path' => $row['storage_path']];
            }
        } while ($rows !== []);
    }
}
