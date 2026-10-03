<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Files\{File, Folder, IRootFolder};
use OCP\IUserManager;

/** Resolve mounted, readable roots, never enumerate their descendants. */
class ScopeSearchScope
{
    public function __construct(private IRootFolder $rootFolder, private IUserManager $users) {}

    /** @return array<string, mixed> Elasticsearch permission prefilter */
    public function resolve(string $userId): array
    {
        try {
            if ($this->users->get($userId) === null) {
                throw new \RuntimeException('Unknown user');
            }
            $folder = $this->rootFolder->getUserFolder($userId);
            $roots = [];
            $files = [];
            if ($folder->isReadable()) {
                $roots[] = (int)$folder->getId();
            }
            $base = rtrim($folder->getPath(), '/') . '/';
            foreach ($this->rootFolder->getMountsIn($folder->getPath()) as $mount) {
                $storage = $mount->getStorage();
                /** @var class-string<\OCP\Files\Storage\IStorage> $aclWrapper */
                $aclWrapper = 'OCA\\GroupFolders\\ACL\\ACLStorageWrapper';
                if ($mount->getMountType() === 'external'
                    || ($storage !== null && $storage->instanceOfStorage($aclWrapper))) {
                    throw new \RuntimeException('ScopeSearch requires standard inherited permissions on local storage');
                }
                $path = rtrim((string)$mount->getMountPoint(), '/');
                if (!str_starts_with($path, $base)) {
                    throw new \RuntimeException('Mount lies outside the user folder');
                }
                // Resolve the exposed node. A jailed mount's physical storage
                // root can include siblings the recipient must never search.
                $node = $folder->get(substr($path, strlen($base)));
                if (!$node->isReadable()) {
                    continue;
                }
                $id = (int)$node->getId();
                if ($id <= 0) {
                    throw new \RuntimeException('Mount has no file id');
                }
                if ($node instanceof Folder) {
                    $roots[] = $id;
                } elseif ($node instanceof File) {
                    $files[] = (string)$id;
                } else {
                    throw new \RuntimeException('Unsupported mount node');
                }
            }
            $alternatives = [];
            foreach (array_chunk(array_values(array_unique($roots)), 50000) as $chunk) {
                $alternatives[] = ['terms' => ['ancestor_ids' => $chunk]];
            }
            foreach (array_chunk(array_values(array_unique($files)), 50000) as $chunk) {
                $alternatives[] = ['ids' => ['values' => $chunk]];
            }
            return $alternatives === [] ? ['match_none' => new \stdClass()]
                : ['bool' => ['should' => $alternatives, 'minimum_should_match' => 1]];
        } catch (\Throwable $e) {
            throw new ExternalServiceException('File permissions could not be resolved.', 'search_scope_unavailable', true, null, 0, $e);
        }
    }
}
