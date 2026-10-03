<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\ScopeSearchScope;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Files\{File, Folder, IRootFolder};
use OCP\Files\Mount\IMountPoint;
use OCP\{IUser, IUserManager};
use PHPUnit\Framework\TestCase;

class ScopeSearchScopeTest extends TestCase
{
    public function testTypicalScopeUsesTwentyRootsAndFiftyFilesWithoutWalkingDescendants(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(10);
        $folder->method('getPath')->willReturn('/alice/files');
        $folder->method('isReadable')->willReturn(true);
        $folder->expects(self::never())->method('search');
        $folder->expects(self::never())->method('getDirectoryListing');
        $mounts = [];
        $nodes = [];
        foreach (range(0, 69) as $i) {
            $mount = $this->createMock(IMountPoint::class);
            $mount->method('getMountPoint')->willReturn('/alice/files/share-' . $i . '/');
            // Physical storage root must NOT broaden a shared subfolder.
            $mount->method('getStorageRootId')->willReturn(999999);
            $node = $this->createMock($i < 20 ? Folder::class : File::class);
            $node->method('getId')->willReturn(100 + $i);
            $node->method('isReadable')->willReturn(true);
            $nodes['share-' . $i] = $node;
            $mounts[] = $mount;
        }
        $folder->expects(self::exactly(70))->method('get')->willReturnCallback(static fn ($path) => $nodes[$path]);
        $scope = $this->service($folder, $mounts)->resolve('alice');
        self::assertSame(array_merge([10], range(100, 119)), $scope['bool']['should'][0]['terms']['ancestor_ids']);
        self::assertSame(array_map('strval', range(120, 169)), $scope['bool']['should'][1]['ids']['values']);
        self::assertSame(1, $scope['bool']['minimum_should_match']);
    }

    public function testMultiplePathsUnionReadableAccessAndDeduplicateFileIds(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(10);
        $folder->method('getPath')->willReturn('/alice/files');
        $folder->method('isReadable')->willReturn(true);
        $mounts = [];
        $nodes = [];
        foreach (['denied', 'readable', 'duplicate'] as $name) {
            $mount = $this->createMock(IMountPoint::class);
            $mount->method('getMountPoint')->willReturn('/alice/files/' . $name);
            $mounts[] = $mount;
            $file = $this->createMock(File::class);
            $file->method('getId')->willReturn(42);
            $file->method('isReadable')->willReturn($name !== 'denied');
            $nodes[$name] = $file;
        }
        $folder->method('get')->willReturnCallback(static fn ($path) => $nodes[$path]);
        self::assertSame(['42'], $this->service($folder, $mounts)->resolve('alice')['bool']['should'][1]['ids']['values']);
    }

    public function testBrokenMountFailsClosedInsteadOfReturningAnIncompleteScope(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(10);
        $folder->method('getPath')->willReturn('/alice/files');
        $folder->method('isReadable')->willReturn(true);
        $folder->method('get')->willThrowException(new \RuntimeException('storage down'));
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountPoint')->willReturn('/alice/files/unavailable');
        $this->expectException(ExternalServiceException::class);
        $this->service($folder, [$mount])->resolve('alice');
    }

    private function service(Folder $folder, array $mounts): ScopeSearchScope
    {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $root->method('getMountsIn')->with('/alice/files')->willReturn($mounts);
        return new ScopeSearchScope($root, $users);
    }
}
