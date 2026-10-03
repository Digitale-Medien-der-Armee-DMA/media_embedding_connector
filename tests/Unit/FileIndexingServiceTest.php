<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\{IndexedFileRepository, SkipMarkerRepository};
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\{AppAccessPolicy, AppConfig, ElasticsearchClient, FileIndexingService, ImageEligibilityService, IndexLifecycleService, MediaLabClient, MediaLabContractService, VectorValidator};
use OCP\Files\{File, IMimeTypeDetector, IRootFolder};
use OCP\Files\Mount\IMountPoint;
use PHPUnit\Framework\TestCase;

class FileIndexingServiceTest extends TestCase
{
    private const CONTRACT = ['model_id' => 'clip', 'model_name' => 'CLIP', 'model_version' => '1', 'model_fingerprint' => 'fp', 'embedding_dim' => 2, 'normalized' => true, 'similarity' => 'cosine'];

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
        return new FileIndexingService(
            $this->createMock(IRootFolder::class), $this->createMock(IMimeTypeDetector::class),
            $this->createMock(MediaLabContractService::class), new ImageEligibilityService(),
            $this->createMock(MediaLabClient::class), new VectorValidator(), $es, $lifecycle,
            $this->createMock(AppConfig::class), $this->createMock(AppAccessPolicy::class),
            $this->createMock(IndexedFileRepository::class), $this->createMock(SkipMarkerRepository::class),
        );
    }
}
