<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Read canonical storage-relative structure from filecache, without opening images. */
class StructureMetadataRepository
{
    private array $ancestors = [];
    private array $files = [];
    public function __construct(private IDBConnection $db) {}

    /** The cache must never survive a worker slice or filesystem mutation. */
    public function resetCache(): void { $this->ancestors = []; $this->files = []; }

    /** @return array<string, mixed>|null */
    public function find(int $fileId): ?array
    {
        if (array_key_exists($fileId, $this->files)) { return $this->files[$fileId]; }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('fileid', 'storage', 'path', 'parent', 'etag')->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

    /** @param list<int> $fileIds */
    public function prime(array $fileIds): void
    {
        foreach ($fileIds as $id) { $this->files[$id] = null; }
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('fileid', 'storage', 'path', 'parent', 'etag')->from('filecache')
                ->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            foreach (ResultCompat::fetchAllAssociative($result) as $row) { $this->files[(int)$row['fileid']] = $row; }
            $result->closeCursor();
        }
    }

    /** @return array<string, mixed>|null */
    public function metadata(int $fileId): ?array
    {
        $row = $this->find($fileId);
        if ($row === null) { return null; }
        $storage = (int)$row['storage'];
        $path = (string)$row['path'];
        $parent = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/')) : '';
        $key = $storage . ':' . $parent;
        if (!isset($this->ancestors[$key])) {
            $paths = [''];
            $prefix = '';
            foreach ($parent === '' ? [] : explode('/', $parent) as $part) {
                $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                $paths[] = $prefix;
            }
            $ids = [];
            foreach (array_chunk($paths, 500) as $chunk) {
                $qb = $this->db->getQueryBuilder();
                $result = $qb->select('fileid')->from('filecache')
                    ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storage, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->in('path_hash', $qb->createNamedParameter(array_map('md5', $chunk), IQueryBuilder::PARAM_STR_ARRAY)))
                    ->executeQuery();
                array_push($ids, ...array_map('intval', ResultCompat::fetchFirstColumn($result)));
                $result->closeCursor();
            }
            if (count($ids) !== count($paths)) { throw new \RuntimeException('File ancestry is incomplete'); }
            if (count($this->ancestors) >= 1000) { $this->ancestors = []; }
            sort($ids, SORT_NUMERIC);
            $this->ancestors[$key] = $ids;
        }
        return ['structure_schema' => 2, 'storage_numeric_id' => $storage,
            'ancestor_ids' => $this->ancestors[$key]];
    }

}
