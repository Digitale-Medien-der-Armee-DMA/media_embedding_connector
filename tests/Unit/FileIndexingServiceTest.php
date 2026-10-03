<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\{IndexedFileRepository, SkipMarkerRepository, StructureMetadataRepository};
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\{AppAccessPolicy, AppConfig, ElasticsearchClient, FileIndexingService, ImageEligibilityService, IndexLifecycleService, MediaLabClient, MediaLabContractService, VectorValidator};
use OCP\Files\{File, IMimeTypeDetector, IRootFolder};
use OCP\Files\Mount\IMountPoint;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class FileIndexingServiceTest extends TestCase
{
    private const CONTRACT = ['model_id' => 'clip', 'model_name' => 'CLIP', 'model_version' => '1', 'model_fingerprint' => 'fp', 'embedding_dim' => 2, 'normalized' => true, 'similarity' => 'cosine'];

    public function testDeleteWorksWithoutResolvingAnEmbeddingContractOrOpeningTheDeletedFile(): void
    {
        $root = $this->createMock(IRootFolder::class);
        $root->expects(self::never())->method('getUserFolder');
        $contracts = $this->createMock(MediaLabContractService::class);
        $contracts->expects(self::never())->method('getDefaultModelContract');
        $mediaLab = $this->createMock(MediaLabClient::class);
        $mediaLab->expects(self::never())->method('embedImageFile');
        $indexed = $this->createMock(IndexedFileRepository::class);
        $indexed->method('find')->with('203677')->willReturn(['index_name' => 'nc_media_embeddings_old_v1']);
        $indexed->expects(self::once())->method('delete')->with('203677');
        $skips = $this->createMock(SkipMarkerRepository::class);
        $skips->expects(self::once())->method('resetFile')->with('203677');
        $lifecycle = $this->createMock(IndexLifecycleService::class);
        $lifecycle->method('getSearchAlias')->willReturn('nc_media_embeddings');
        $deletedFrom = [];
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::exactly(2))->method('deleteDocument')->willReturnCallback(
            static function (string $index, string $fileId) use (&$deletedFrom): void {
                self::assertSame('203677', $fileId);
                $deletedFrom[] = $index;
            },
        );
        $service = new FileIndexingService(
            $root, $this->createMock(IMimeTypeDetector::class), $contracts,
            new ImageEligibilityService(), $mediaLab, new VectorValidator(), $es, $lifecycle,
            $this->createMock(AppConfig::class), $this->createMock(AppAccessPolicy::class), $indexed, $skips, $this->createMock(StructureMetadataRepository::class), $this->createMock(ILockingProvider::class),
        );
        self::assertSame('indexed', $service->process(['action' => 'delete', 'file_id' => '203677']));
        self::assertSame(['nc_media_embeddings_old_v1', 'nc_media_embeddings'], $deletedFrom);
    }

    public function testChangedEmbeddingModelCannotWriteToThePreparedIndex(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::never())->method('upsertDocument');
        $service = $this->service($es);
        $this->expectException(ExternalServiceException::class);
        $service->indexPreparedImage(['contract' => self::CONTRACT], ['model_id' => 'clip', 'model_fingerprint' => 'other', 'image_vector' => [1.0, 0.0]]);
    }

    public function testPreparedWriteIndexIsUsedInsteadOfReadingANewTarget(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('upsertDocument')->with('nc_media_embeddings_prepared_v1', '42', self::callback(
            static fn (array $document): bool => $document['model_fingerprint'] === 'fp' && $document['image_vector'] === [1.0, 0.0],
        ));
        $node = $this->createMock(File::class);
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getStorageId')->willReturn(7);
        $node->method('getMountPoint')->willReturn($mount);
        $node->method('getEtag')->willReturn('etag');
        $service = $this->service($es);
        self::assertSame('indexed', $service->indexPreparedImage([
            'contract' => self::CONTRACT, 'node' => $node, 'file_id' => '42', 'owner_uid' => 'alice',
            'mime_type' => 'image/jpeg', 'write_index' => 'nc_media_embeddings_prepared_v1',
        ], self::CONTRACT + ['image_vector' => [1.0, 0.0]]));
    }

    private function service(ElasticsearchClient $es): FileIndexingService
    {
        $lifecycle = $this->createMock(IndexLifecycleService::class);
        $lifecycle->method('getStatus')->willReturn(['active_contract' => self::CONTRACT]);
        $lifecycle->expects(self::never())->method('getWriteIndex');
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $metadata->method('metadata')->willReturn(['structure_schema' => 2, 'storage_numeric_id' => 7, 'ancestor_ids' => [1, 3]]);
        return new FileIndexingService(
            $this->createMock(IRootFolder::class), $this->createMock(IMimeTypeDetector::class),
            $this->createMock(MediaLabContractService::class), new ImageEligibilityService(),
            $this->createMock(MediaLabClient::class), new VectorValidator(), $es, $lifecycle,
            $this->createMock(AppConfig::class), $this->createMock(AppAccessPolicy::class),
            $this->createMock(IndexedFileRepository::class), $this->createMock(SkipMarkerRepository::class), $metadata, $this->createMock(ILockingProvider::class),
        );
    }
}
