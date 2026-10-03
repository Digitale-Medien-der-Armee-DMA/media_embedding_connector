<?php

declare(strict_types=1);

// Disposable Nextcloud instance only; SCOPE_ES_URL must point to a disposable ES.
define('OC_CONSOLE', true);
require dirname(__DIR__, 4) . '/lib/base.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e . "\n"); exit(1); });

use OCA\MediaEmbeddingConnector\Db\{IndexedFileRepository, StateRepository, StructureMetadataRepository, StructureTaskRepository};
use OCA\MediaEmbeddingConnector\Service\{AppConfig, ElasticsearchClient, ImageSearchService, IndexLifecycleService, IndexMappingFactory, ScopeSearchScope, StructureMigrationService, FileIndexingService};
use OCP\Files\IRootFolder;
use OCP\{IGroupManager, IUser, IUserManager, IUserSession, Server};
use OCP\Share\{IManager, IShare};

function verify(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function switchUser(IUser $user): void {
    \OC\Files\Filesystem::tearDown();
    Server::get(IUserSession::class)->setUser($user);
    \OC_Util::setupFS($user->getUID());
}
$url = getenv('SCOPE_ES_URL');
verify(is_string($url) && $url !== '', 'SCOPE_ES_URL is required.');
$users = Server::get(IUserManager::class);
verify($users->get('scope-owner') === null && $users->get('scope-viewer') === null, 'A disposable instance is required.');
$config = Server::get(AppConfig::class);
$config->setElasticsearchConfigSource(AppConfig::ELASTICSEARCH_SOURCE_CUSTOM);
$config->setElasticsearchUrl($url);
$config->setAllowPrivateNetworks(true);
$config->setIndexAlias('nc_media_embeddings_scope_runtime');
$config->setIndexingEnabled(false);
$es = Server::get(ElasticsearchClient::class);
$index = 'nc_media_embeddings_scope_runtime_v1';
$contract = ['model_name' => 'CLIP', 'model_id' => 'clip', 'model_fingerprint' => 'scope-runtime-fp', 'model_version' => '1',
    'embedding_dim' => 2, 'normalized' => true, 'similarity' => 'cosine'];
$mapping = Server::get(IndexMappingFactory::class)->buildFromContract($contract)['mapping'];
foreach (['structure_schema', 'storage_numeric_id', 'ancestor_ids'] as $field) { unset($mapping['mappings']['properties'][$field]); }
$es->createIndex($index, $mapping);
$writeIndex = 'nc_media_embeddings_scope_runtime_write_v1';
$es->createIndex($writeIndex, $mapping);
$es->swapAlias($config->getIndexAlias(), $index);
$state = Server::get(StateRepository::class);
$state->set(IndexLifecycleService::STATE_WRITE_INDEX, $writeIndex);
$state->set(IndexLifecycleService::STATE_SEARCH_INDEX, $index);
$state->setJson(IndexLifecycleService::STATE_ACTIVE_CONTRACT, $contract);
$created = [];
$groups = [];
$records = Server::get(IndexedFileRepository::class);
$metadata = Server::get(StructureMetadataRepository::class);
$migration = Server::get(StructureMigrationService::class);
$seedIds = [];
try {
    foreach (['scope-owner', 'scope-viewer'] as $uid) {
        $user = $users->createUser($uid, bin2hex(random_bytes(24)));
        verify($user instanceof IUser, 'Cannot create user');
        $created[] = $user;
    }
    [$owner, $viewer] = $created;
    $groupManager = Server::get(IGroupManager::class);
    foreach (['scope-group-a', 'scope-group-b'] as $gid) {
        $group = $groupManager->createGroup($gid);
        verify($group !== null, 'Cannot create group');
        $group->addUser($viewer);
        $groups[] = $group;
    }
    switchUser($owner);
    $root = Server::get(IRootFolder::class);
    $ownerFiles = $root->getUserFolder($owner->getUID());
    $parent = $ownerFiles->newFolder('private-parent');
    $allowed = $parent->newFolder('allowed');
    $destination = $ownerFiles->newFolder('destination');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jX1sAAAAASUVORK5CYII=', true);
    $shared = $allowed->newFile('shared.png', $png);
    $second = $allowed->newFile('second.png', $png);
    $private = $parent->newFile('private.png', $png);
    $shares = Server::get(IManager::class);
    $groupShares = [];
    foreach ($groups as $group) {
        $share = $shares->newShare();
        $share->setNode($allowed)->setSharedBy($owner->getUID())->setShareType(IShare::TYPE_GROUP)
            ->setSharedWith($group->getGID())->setPermissions(\OCP\Constants::PERMISSION_READ);
        $groupShares[] = $shares->createShare($share);
    }
    $share = $shares->newShare();
    $share->setNode($shared)->setSharedBy($owner->getUID())->setShareType(IShare::TYPE_USER)
        ->setSharedWith($viewer->getUID())->setPermissions(\OCP\Constants::PERMISSION_READ);
    $direct = $shares->createShare($share);
    switchUser($viewer);
    $own = $root->getUserFolder($viewer->getUID())->newFile('own.png', $png);
    foreach ([$shared, $second, $private, $own] as $file) {
        $id = (string)$file->getId();
        $seedIds[] = $id;
        $es->upsertDocument($index, $id, ['nextcloud_file_id' => $id, 'mime_type' => 'image/png', 'storage_id' => 'home::scope-owner', 'model_fingerprint' => 'scope-runtime-fp', 'image_vector' => [1.0, 0.0]]);
        $records->upsert(['file_id' => $id, 'storage_id' => (string)$file->getMountPoint()->getStorageId(),
            'owner_uid' => $file->getOwner()->getUID(), 'etag' => $file->getEtag(), 'mime_type' => 'image/png',
            'size_bytes' => $file->getSize(), 'mtime' => $file->getMTime(), 'index_name' => $index,
            'model_fingerprint' => 'scope-runtime-fp']);
    }
    $es->upsertDocument($writeIndex, (string)$own->getId(), ['nextcloud_file_id' => (string)$own->getId(),
        'mime_type' => 'image/png', 'model_fingerprint' => 'scope-runtime-fp', 'image_vector' => [1.0, 0.0]]);
    // The ES corpus can contain vectors absent from connector tracking records.
    $records->delete((string)$own->getId());
    $before = $es->getDocumentVector($index, (string)$shared->getId());
    $migration->control('restart');
    $migration->control('pause');
    $migration->runSlice(1);
    verify($migration->getStatus()['processed'] === 0, 'Pause did not stop migration');
    $migration->control('resume');
    $migration->runSlice(10);
    verify($migration->getStatus()['status'] === 'completed', 'Metadata migration failed: ' . json_encode($migration->getStatus()));
    verify($migration->getStatus()['processed'] === 5, 'Metadata migration count is wrong');
    verify($es->getDocumentVector($index, (string)$shared->getId()) === $before, 'Metadata migration changed a vector');
    $source = json_decode(file_get_contents($url . '/' . $index . '/_source/' . $shared->getId()), true);
    verify(!isset($source['storage_id']), 'Legacy user-bearing storage id was not removed');
    $source = json_decode(file_get_contents($url . '/' . $writeIndex . '/_source/' . $own->getId()), true);
    verify(($source['structure_schema'] ?? null) === 2, 'Distinct write index was not upgraded');
    $indexer = Server::get(FileIndexingService::class);
    verify($indexer->indexPreparedImage(['contract' => $contract, 'node' => $own, 'file_id' => (string)$own->getId(),
        'owner_uid' => $viewer->getUID(), 'mime_type' => 'image/png', 'write_index' => $writeIndex],
        $contract + ['image_vector' => [1.0, 0.0]]) === 'indexed', 'Normal indexing failed to write schema-2 structure');
    $prepared = $indexer->prepareImageForEmbedding(['file_id' => (string)$own->getId(), 'owner_uid' => $viewer->getUID()], $contract);
    verify(($prepared['reason'] ?? null) === 'unchanged', 'Metadata update forced an unchanged image to be re-embedded');
    $scope = Server::get(ScopeSearchScope::class);
    $filter = $scope->resolve($viewer->getUID());
    $pit = $es->openSearchSnapshot($index);
    try {
        $hits = $es->searchScope($index, [1.0, 0.0], 49, $filter, null, true, 'scope-runtime-fp', $pit);
        $ids = array_column($hits, 'file_id');
        verify(count($ids) === 3 && !in_array((string)$private->getId(), $ids, true), 'Overlapping/group scope leaked parent or duplicated hits');
    } finally { $es->closeSearchSnapshot($pit); }
    $search = Server::get(ImageSearchService::class);
    $page = $search->searchSimilar($viewer->getUID(), (string)$own->getId(), 49, 0);
    verify(count($page['results']) === 3 && $page['search_mode'] === 'exact', 'End-to-end similarity search failed');
    // Remove one group route. The remaining group and direct route must survive.
    switchUser($owner);
    $shares->deleteShare($groupShares[0]);
    switchUser($viewer);
    $page = $search->searchSimilar($viewer->getUID(), (string)$own->getId(), 49, 0);
    verify(count($page['results']) === 3, 'Removing one share route removed other readable routes');
    $groups[1]->removeUser($viewer);
    switchUser($viewer);
    $page = $search->searchSimilar($viewer->getUID(), (string)$own->getId(), 49, 0);
    verify(count($page['results']) === 2, 'Removed group membership remains searchable');
    $groups[1]->addUser($viewer);
    switchUser($viewer);
    $page = $search->searchSimilar($viewer->getUID(), (string)$own->getId(), 49, 0);
    verify(count($page['results']) === 3, 'New group membership is missing from live search scope');
    switchUser($owner);
    $oldParentId = $parent->getId();
    $allowedId = $allowed->getId();
    $allowed->move($destination->getPath() . '/allowed');
    verify($migration->getStatus()['repair_pending'], 'Folder move did not queue persistent repair');
    $migration->runSlice(10);
    verify(!$migration->getStatus()['repair_pending'], 'Folder repair did not finish');
    $source = json_decode(file_get_contents($url . '/' . $index . '/_source/' . $shared->getId()), true);
    verify(in_array($destination->getId(), $source['ancestor_ids'], true) && !in_array($oldParentId, $source['ancestor_ids'], true), 'Moved folder ancestry is stale');
    verify($es->getDocumentVector($index, (string)$shared->getId()) === $before, 'Folder repair changed embedding');
    // Move a file out of the still-shared root. Old vector ancestry must be removed.
    $movedFile = $ownerFiles->get('destination/allowed/second.png');
    $movedFile->move($parent->getPath() . '/second.png');
    $migration->runSlice(10);
    switchUser($viewer);
    $page = $search->searchSimilar($viewer->getUID(), (string)$own->getId(), 49, 0);
    verify(count($page['results']) === 2, 'File moved out of a share remains searchable');
    switchUser($owner);
    $ownerFiles->get('destination/allowed')->delete();
    $migration->runSlice(10);
    verify($es->getDocumentVector($index, (string)$shared->getId()) === null, 'Folder deletion left vector behind');
    // Different user homes are distinct logical storages even on one datadir.
    // Standard local cache moves preserve IDs; every descendant must get the
    // new storage and ancestry without requesting replacement embeddings.
    $transfer = $ownerFiles->newFolder('transfer');
    $nested = $transfer->newFolder('nested');
    $transferFile = $nested->newFile('transfer.png', $png);
    $transferId = (string)$transferFile->getId();
    $transferRootId = $transfer->getId();
    $metadata->resetCache();
    $oldStructure = $metadata->metadata((int)$transferId);
    $seedIds[] = $transferId;
    $es->upsertDocument($index, $transferId, $oldStructure + ['nextcloud_file_id' => $transferId,
        'mime_type' => 'image/png', 'model_fingerprint' => 'scope-runtime-fp', 'image_vector' => [1.0, 0.0]]);
    $share = $shares->newShare();
    $share->setNode($transfer)->setSharedBy($owner->getUID())->setShareType(IShare::TYPE_USER)
        ->setSharedWith($viewer->getUID())->setPermissions(\OCP\Constants::PERMISSION_ALL);
    $transferShare = $shares->createShare($share);
    switchUser($viewer);
    $viewerFiles = $root->getUserFolder($viewer->getUID());
    $mountedTransfer = $viewerFiles->getById($transferRootId)[0];
    $mountedTransfer->get('nested')->move($viewerFiles->getPath() . '/received');
    $received = $viewerFiles->get('received/transfer.png');
    verify((string)$received->getId() === $transferId, 'Supported local storage move unexpectedly changed IDs');
    $metadata->resetCache();
    $newStructure = $metadata->metadata((int)$transferId);
    verify($newStructure['storage_numeric_id'] !== $oldStructure['storage_numeric_id'], 'Test did not move between logical storages: ' . json_encode([$oldStructure, $newStructure]));
    verify($migration->getStatus()['repair_pending'], 'Cross-storage move did not queue repair');
    $migration->runSlice(10);
    $source = json_decode(file_get_contents($url . '/' . $index . '/_source/' . $transferId), true);
    verify($source['storage_numeric_id'] !== $oldStructure['storage_numeric_id'], 'Cross-storage move kept old storage metadata');
    verify(in_array($viewerFiles->getId(), $source['ancestor_ids'], true)
        && !in_array($ownerFiles->getId(), $source['ancestor_ids'], true), 'Cross-storage move kept old ancestry');
    verify($es->getDocumentVector($index, $transferId) === [1.0, 0.0], 'Cross-storage move changed embedding');
    $migration->control('restart');
    $migration->runSlice(10);
    verify($migration->getStatus()['status'] === 'completed', 'Restart did not recover cleanly');
    echo "ScopeSearch end-to-end passed: existing vectors, pause/resume/restart, live group memberships, overlapping shares, subtree and cross-storage moves, deletion.\n";
} finally {
    Server::get(IUserSession::class)->setUser(null);
    \OC\Files\Filesystem::tearDown();
    foreach (array_reverse($created) as $user) { $user->delete(); }
    foreach ($groups as $group) { $group->delete(); }
    foreach ($seedIds as $id) { $records->delete($id); }
    $es->deleteManagedIndex($index);
    $es->deleteManagedIndex($writeIndex);
}
