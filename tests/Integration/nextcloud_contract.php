<?php

declare(strict_types=1);

// Run as the web-server user inside a disposable, installed Nextcloud instance.
define('OC_CONSOLE', true);
// Nextcloud's default exception handler logs and exits without a failing status.
// Ensure a runtime assertion or API error always fails this contract test.
register_shutdown_function(static function (): void {
    if (!defined('CONNECTOR_RUNTIME_PASSED')) {
        fwrite(STDERR, "Nextcloud runtime contract did not complete. Check the Nextcloud log.\n");
        exit(1);
    }
});
require dirname(__DIR__, 4) . '/lib/base.php';
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, (string)$e . "\n");
    exit(1);
});

use OCA\MediaEmbeddingConnector\Db\SearchSessionRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\FileStatusExportService;
use OCA\MediaEmbeddingConnector\Service\ImageSearchService;
use OCA\MediaEmbeddingConnector\Service\ScopeSearchScope;
use OCA\MediaEmbeddingConnector\Db\StructureMetadataRepository;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function asUser(IUser $user): void {
    \OC\Files\Filesystem::tearDown();
    Server::get(IUserSession::class)->setUser($user);
    \OC_Util::setupFS($user->getUID());
}

$users = Server::get(IUserManager::class);
$created = [];
try {
    foreach (['contract-owner', 'contract-viewer'] as $uid) {
        check($users->get($uid) === null, 'Runtime contract requires a disposable instance.');
        $user = $users->createUser($uid, bin2hex(random_bytes(24)));
        check($user instanceof IUser, 'Could not create test user.');
        $created[] = $user;
    }
    [$owner, $viewer] = $created;
    $root = Server::get(IRootFolder::class);
    // Small, valid PNG fixture. All files and shares stay in the test instance.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jX1sAAAAASUVORK5CYII=', true);
    asUser($owner);
    $ownerFolder = $root->getUserFolder($owner->getUID());
    $shared = $ownerFolder->newFile('contract-shared.png', $png);
    $private = $ownerFolder->newFile('contract-private.png', $png);
    $shares = Server::get(IManager::class);
    $share = $shares->newShare();
    $share->setNode($shared)->setSharedBy($owner->getUID())
        ->setShareType(IShare::TYPE_USER)->setSharedWith($viewer->getUID())
        ->setPermissions(\OCP\Constants::PERMISSION_READ);
    $share = $shares->createShare($share);

    asUser($viewer);
    $own = $root->getUserFolder($viewer->getUID())->newFile('contract-owned.png', $png);
    $scope = Server::get(ScopeSearchScope::class);
    $metadata = Server::get(StructureMetadataRepository::class);
    $filter = $scope->resolve($viewer->getUID());
    $allows = static function (int $id, array $filter) use ($metadata): bool {
        $structure = $metadata->metadata($id);
        foreach ($filter['bool']['should'] ?? [] as $clause) {
            if (in_array((string)$id, $clause['ids']['values'] ?? [], true)
                || array_intersect($structure['ancestor_ids'] ?? [], $clause['terms']['ancestor_ids'] ?? []) !== []) {
                return true;
            }
        }
        return false;
    };
    check($allows($own->getId(), $filter), 'Own photo missing from scope.');
    check($allows($shared->getId(), $filter), 'Received share missing from scope.');
    check(!$allows($private->getId(), $filter), 'Private photo leaked into scope.');

    $sessions = Server::get(SearchSessionRepository::class);
    $session = $sessions->create($viewer->getUID(), [
        'candidates' => [
            ['file_id' => (string)$shared->getId(), 'score' => 0.9],
            ['file_id' => (string)$own->getId(), 'score' => 0.8],
            ['file_id' => (string)$private->getId(), 'score' => 0.7],
        ],
        'reference_id' => null, 'search_mode' => 'exact', 'result_limit_reached' => false,
    ]);
    $search = Server::get(ImageSearchService::class);
    $page = $search->searchPage($viewer->getUID(), $session['session_id'], 49, 0);
    check(array_column($page['results'], 'file_id') === [(string)$shared->getId(), (string)$own->getId()],
        'Search page must recheck real filesystem permissions.');
    try {
        $sessions->load($owner->getUID(), $session['session_id']);
        throw new RuntimeException('Another user could load the search session.');
    } catch (ExternalServiceException $e) {
        check($e->getPublicCode() === 'search_session_expired', 'Unexpected session ownership error.');
    }

    asUser($owner);
    $shares->deleteShare($share);
    asUser($viewer);
    check(!$allows($shared->getId(), $scope->resolve($viewer->getUID())), 'Revoked share remains in new live scope.');
    $page = $search->searchPage($viewer->getUID(), $session['session_id'], 49, 0);
    check(array_column($page['results'], 'file_id') === [(string)$own->getId()],
        'Revoked share must disappear from a cached search session.');

    $exports = Server::get(FileStatusExportService::class);
    foreach (['queued', 'running', 'failed', 'skipped'] as $status) {
        $stream = $exports->create($status);
        $export = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
        fclose($stream);
        check(is_array($export) && array_is_list($export), 'Invalid status export.');
        foreach ($export as $entry) {
            check(array_keys($entry) === ['id', 'last_error', 'storage_path'], 'Unexpected export fields.');
        }
    }
    check($sessions->purgeExpired() >= 0, 'Session cleanup failed.');
} finally {
    Server::get(IUserSession::class)->setUser(null);
    \OC\Files\Filesystem::tearDown();
    foreach (array_reverse($created) as $user) {
        $user->delete();
    }
}
define('CONNECTOR_RUNTIME_PASSED', true);
echo "Nextcloud runtime contract passed: migrations, scope, shares, revocation, sessions and exports.\n";
