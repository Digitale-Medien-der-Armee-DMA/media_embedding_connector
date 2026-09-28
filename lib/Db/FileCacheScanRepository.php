<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;

/**
 * Keyset-paginated reads from Nextcloud's file cache for the backfill scan.
 *
 * Pages are selected per storage and MIME type and ordered by file id, so the
 * (storage, mimetype) index can serve every page without an offset and a scan
 * can resume exactly after the last file id it processed.
 */
class FileCacheScanRepository
{
    private const TABLE = 'filecache';

    public function __construct(
        private IDBConnection $db,
        private IMimeTypeLoader $mimeTypeLoader,
    ) {
    }

    /**
     * @param list<string> $mimeTypes
     * @return array<int, string> MIME type ids that exist in this instance, mapped to their names
     */
    public function resolveMimeTypeIds(array $mimeTypes): array
    {
        $ids = [];
        foreach ($mimeTypes as $mimeType) {
            if ($this->mimeTypeLoader->exists($mimeType)) {
                $ids[$this->mimeTypeLoader->getId($mimeType)] = $mimeType;
            }
        }
        ksort($ids);
        return $ids;
    }

    /**
     * Resolves a folder to the storage and path prefix that contain its files.
     *
     * @return array{storage: int, prefix: string}|null
     */
    public function findScanRoot(int $folderId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('storage', 'path')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = ResultCompat::fetchAssociative($result);
        $result->closeCursor();
        if (!is_array($row)) {
            return null;
        }

        $path = trim((string)$row['path'], '/');
        return [
            'storage' => (int)$row['storage'],
            'prefix' => $path === '' ? '' : $path . '/',
        ];
    }

    /**
     * @return list<array{fileid: int, name: string, etag: string}>
     */
    public function findFilesAfter(int $storage, string $prefix, int $mimeTypeId, int $afterFileId, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'name', 'etag')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storage, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('fileid', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('fileid', 'ASC')
            ->setMaxResults(max(1, $limit));
        if ($prefix !== '') {
            $qb->andWhere($qb->expr()->like(
                'path',
                $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%'),
            ));
        }
        $result = $qb->executeQuery();
        $rows = [];
        foreach (ResultCompat::fetchAllAssociative($result) as $row) {
            $rows[] = [
                'fileid' => (int)$row['fileid'],
                'name' => (string)$row['name'],
                'etag' => (string)$row['etag'],
            ];
        }
        $result->closeCursor();
        return $rows;
    }
}
