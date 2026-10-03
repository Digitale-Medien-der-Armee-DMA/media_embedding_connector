<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\{VisibleFileScope, ImageEligibilityService, FileScopeQuery};
use OCA\MediaEmbeddingConnector\Db\VisibleFileCandidateRepository;
use OCP\Files\{File, Folder, IRootFolder};
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Search\ISearchComparison;
use OCP\{IUser, IUserManager};
use PHPUnit\Framework\TestCase;

class VisibleFileScopeTest extends TestCase
{
    public function testAllPagesAndMountsAreIncludedEvenAfterADeniedPage(): void
    {
        $user = $this->createMock(IUser::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(10);
        $folder->method('getPath')->willReturn('/alice/files');
        $folder->method('search')->willReturnCallback(function (FileScopeQuery $query) use ($user): array {
            self::assertSame($user, $query->getUser());
            self::assertFalse($query->limitToHome());
            self::assertSame(0, $query->getLimit());
            self::assertSame(ISearchComparison::COMPARE_IN, $query->getSearchOperation()->getType());
            $ids = $query->getSearchOperation()->getValue();
            $nodes = [];
            // A fully denied/filtered page must not end the candidate scan.
            if ($ids === [10004]) { return []; }
            foreach ($ids as $id) {
                $nodes[] = $this->file($id, $id !== 1001);
            }
            // Simulate the same shared file mounted twice with different rights.
            if (in_array(10001, $ids, true)) { $nodes[] = $this->file(10001, false); }
            return array_reverse($nodes);
        });
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getStorageRootId')->willReturn(20);
        $root->method('getMountsIn')->with('/alice/files')->willReturn([$mount]);
        $candidates = $this->createMock(VisibleFileCandidateRepository::class);
        $candidates->method('batches')->with([10, 20], 1000)->willReturnCallback(static function (): \Generator {
            yield [10004];
            yield from array_chunk(range(1, 10003), 1000);
        });
        $scope = new VisibleFileScope($root, $users, new ImageEligibilityService(), $candidates);
        $batches = iterator_to_array($scope->batches('alice'));
        self::assertCount(1, $batches);
        self::assertCount(10002, $batches[0]);
        $ids = array_merge(...$batches);
        self::assertCount(10002, $ids);
        self::assertNotContains('1001', $ids);
        self::assertContains('10001', $ids);
        self::assertContains('10003', $ids);
    }

    public function testUnknownUserFailsInsteadOfFallingBackToGlobalSearch(): void
    {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn(null);
        $scope = new VisibleFileScope($this->createMock(IRootFolder::class), $users, new ImageEligibilityService(),
            $this->createMock(VisibleFileCandidateRepository::class));
        $this->expectException(\OCA\MediaEmbeddingConnector\Exception\ExternalServiceException::class);
        iterator_to_array($scope->batches('missing'));
    }

    private function file(int $id, bool $readable): File
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn('photo.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('isReadable')->willReturn($readable);
        return $file;
    }
}
