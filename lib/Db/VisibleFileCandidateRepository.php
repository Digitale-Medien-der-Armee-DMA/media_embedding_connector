<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** File-cache candidates only. Every candidate must pass the mounted filesystem ACL check. */
class VisibleFileCandidateRepository
{
    public function __construct(private IDBConnection $db) {}

    /** @param list<int> $rootIds
     * @return \Generator<int, list<int>>
     */
    public function batches(array $rootIds, int $limit): \Generator
    {
        $rootIds = array_values(array_unique($rootIds));
        $regions = [];
        foreach (array_chunk($rootIds, 250) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('fileid', 'storage', 'path')->from('filecache')
                ->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            $rows = ResultCompat::fetchAllAssociative($result);
            $result->closeCursor();
            if (count($rows) !== count($chunk)) {
                throw new ExternalServiceException('Mounted file-cache root is unavailable.', 'search_scope_unavailable');
            }
            foreach ($rows as $row) {
                $regions[(int)$row['storage']][] = (string)$row['path'];
            }
        }
        foreach ($regions as $storage => $paths) {
            // Collapse overlapping mounts so files are neither rescanned nor duplicated.
            usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
            $roots = [];
            foreach ($paths as $path) {
                foreach ($roots as $parent) {
                    if ($parent === '' || $path === $parent || str_starts_with($path, $parent . '/')) {
                        continue 2;
                    }
                }
                $roots[] = $path;
            }
            foreach ($roots as $path) {
                $after = 0;
                do {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('fileid')->from('filecache')
                        ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storage, IQueryBuilder::PARAM_INT)))
                        ->andWhere($qb->expr()->gt('fileid', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
                        ->orderBy('fileid', 'ASC')->setMaxResults(max(1, $limit));
                    if ($path !== '') {
                        $qb->andWhere($qb->expr()->orX(
                            $qb->expr()->eq('path', $qb->createNamedParameter($path)),
                            $qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter($path . '/') . '%')),
                        ));
                    }
                    $result = $qb->executeQuery();
                    $ids = array_map('intval', ResultCompat::fetchFirstColumn($result));
                    $result->closeCursor();
                    if ($ids !== []) {
                        $after = max($ids);
                        yield $ids;
                    }
                } while ($ids !== []);
            }
        }
    }
}
