<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\SearchSessionRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\{ElasticsearchClient, ImageEligibilityService, ImageEmbeddingService, ImageSearchService, IndexLifecycleService, MediaLabClient, MediaLabContractService, VectorValidator, VisibleFileScope};
use OCP\Files\{File, Folder, IRootFolder};
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ImageSearchServiceTest extends TestCase
{
    private array $payload = [];
    private array $denied = [];
    private bool $lookupFailure = false;
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT = ['model_id' => 'clip', 'model_fingerprint' => 'fp', 'embedding_dim' => 2, 'normalized' => true, 'similarity' => 'cosine'];

    public function testEveryScopeBlockContributesToTheFrozenGlobalRanking(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::exactly(2))->method('search')->willReturnCallback(
            static function ($index, $vector, $limit, $ids, $exclude, $exact, $fingerprint, $pit): array {
                self::assertSame('nc_media_embeddings_test_v1', $index);
                self::assertSame(501, $limit);
                self::assertSame('fp', $fingerprint);
                self::assertSame('snapshot', $pit);
                self::assertFalse($exact);
                return $ids === ['10', '11']
                    ? [['file_id' => '10', 'score' => 0.6], ['file_id' => '11', 'score' => 0.5]]
                    : [['file_id' => '20', 'score' => 0.9], ['file_id' => '21', 'score' => 0.8]];
            },
        );
        $service = $this->service($es, [['10', '11'], ['20', '21']]);
        $first = $service->searchText('alice', 'forest', 2, 0);
        self::assertSame(['20', '21'], array_column($first['results'], 'file_id'));
        self::assertSame('ann', $first['search_mode']);
        $second = $service->searchPage('alice', self::TOKEN, 2, $first['next_offset']);
        self::assertSame(['10', '11'], array_column($second['results'], 'file_id'));
        self::assertFalse($second['has_more']);
    }

    public function testSmallCompleteScopeUsesExactSearchAndNeverReEmbedsForPages(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('search')
            ->with('nc_media_embeddings_test_v1', [1.0, 0.0], 501, ['10', '11'], null, true, 'fp', 'snapshot')
            ->willReturn([['file_id' => '10', 'score' => 0.9], ['file_id' => '11', 'score' => 0.8]]);
        $service = $this->service($es, [['10', '11']]);
        $first = $service->searchText('alice', 'forest', 1, 0);
        self::assertSame('exact', $first['search_mode']);
        $second = $service->searchPage('alice', self::TOKEN, 1, $first['next_offset']);
        self::assertSame('11', $second['results'][0]['file_id']);
    }

    public function testOneLargeBlockDoesNotAccidentallyUseExactSearch(): void
    {
        $ids = array_map('strval', range(1, 10001));
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('search')
            ->with('nc_media_embeddings_test_v1', [1.0, 0.0], 501, $ids, null, false, 'fp', 'snapshot')->willReturn([]);
        self::assertSame('ann', $this->service($es, [$ids])->searchText('alice', 'forest', 49, 0)['search_mode']);
    }

    public function testEmptyScopeNeverQueriesTheGlobalIndexOrOpensSnapshot(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::never())->method('search');
        $es->expects(self::never())->method('openSearchSnapshot');
        $es->expects(self::never())->method('closeSearchSnapshot');
        self::assertSame([], $this->service($es, [], false)->searchText('alice', 'forest', 49, 0)['results']);
    }

    public function testRevokedFilesAreReplacedWithoutShiftingEarlierPages(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->method('search')->willReturn(array_map(static fn ($id): array => ['file_id' => (string)$id, 'score' => 1.0 - $id / 100], range(1, 6)));
        $service = $this->service($es, [['1', '2', '3', '4', '5', '6']]);
        $first = $service->searchText('alice', 'forest', 2, 0);
        $this->denied = ['1', '3', '4'];
        $second = $service->searchPage('alice', self::TOKEN, 2, $first['next_offset']);
        self::assertSame(['5', '6'], array_column($second['results'], 'file_id'));
        self::assertFalse($second['has_more']);
    }

    public function testTechnicalPermissionFailureDoesNotReturnSilentPartialResults(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->method('search')->willReturn([['file_id' => '10', 'score' => 1.0]]);
        $service = $this->service($es, [['10']]);
        $this->lookupFailure = true;
        $this->expectException(ExternalServiceException::class);
        $this->expectExceptionMessage('File permissions could not be resolved.');
        $service->searchText('alice', 'forest', 49, 0);
    }

    public function testSimilarReferenceFitsWithinPageAndIsExcludedFromOtherHits(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('getDocumentVector')->with('nc_media_embeddings_test_v1', '99', 'fp')->willReturn([1.0, 0.0]);
        $es->expects(self::once())->method('search')->willReturnCallback(static function ($index, $vector, $limit, $ids, $exclude): array {
            self::assertSame('99', $exclude);
            return array_map(static fn ($id): array => ['file_id' => (string)$id, 'score' => 1.0 - $id / 1000], range(1, 100));
        });
        $service = $this->service($es, [['10']], true, false);
        $first = $service->searchSimilar('alice', '99', 49, 0);
        self::assertCount(49, $first['results']);
        self::assertTrue($first['results'][0]['is_reference']);
        self::assertSame('48', $first['results'][48]['file_id']);
        $second = $service->searchPage('alice', self::TOKEN, 49, $first['next_offset']);
        self::assertSame('49', $second['results'][0]['file_id']);
        self::assertCount(49, $second['results']);
    }

    public function testDefaultModelMismatchBlocksInferenceBeforeItCanCorruptSearch(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::never())->method('search');
        $service = $this->service($es, [], false, false, 'new-fp');
        $this->expectException(ExternalServiceException::class);
        $this->expectExceptionMessage('Embedding model differs from the search index.');
        $service->searchText('alice', 'forest', 49, 0);
    }

    public function testInferenceResponseIsCheckedAgainForMidRequestModelChanges(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::never())->method('search');
        $service = $this->service($es, [], false, true, 'fp', 'other');
        $this->expectException(ExternalServiceException::class);
        $service->searchText('alice', 'forest', 49, 0);
    }

    public function testFailedSearchClosesSnapshotAndNeverPersistsPartialRanking(): void
    {
        $es = $this->createMock(ElasticsearchClient::class);
        $es->method('search')->willThrowException(new ExternalServiceException('Incomplete', 'search_incomplete'));
        $service = $this->service($es, [['10']]);
        try { $service->searchText('alice', 'forest', 49, 0); self::fail('Expected search failure'); }
        catch (ExternalServiceException) { self::assertSame([], $this->payload); }
    }

    private function service(ElasticsearchClient $es, array $batches, bool $snapshot = true, bool $text = true, string $defaultFingerprint = 'fp', string $responseFingerprint = 'fp'): ImageSearchService
    {
        $scope = $this->createMock(VisibleFileScope::class);
        // Only the first request may enumerate or embed; page requests must reuse.
        if ($text && $defaultFingerprint === 'fp' && $responseFingerprint === 'fp' || !$text && $snapshot) {
            $scope->expects(self::once())->method('batches')->willReturnCallback(static function () use ($batches): \Generator { yield from $batches; });
        }
        $es->method('resolveSearchIndex')->willReturn('nc_media_embeddings_test_v1');
        $es->method('getIndexContract')->willReturn(self::CONTRACT);
        if ($snapshot) {
            $es->expects(self::once())->method('openSearchSnapshot')->willReturn('snapshot');
            $es->expects(self::once())->method('closeSearchSnapshot')->with('snapshot');
        }
        $lifecycle = $this->createMock(IndexLifecycleService::class);
        $lifecycle->method('getSearchAlias')->willReturn('nc_media_embeddings_search');
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->willReturnCallback(function (int $id): array {
            if ($this->lookupFailure) { throw new \RuntimeException('storage offline'); }
            $file = $this->createMock(File::class);
            $file->method('getId')->willReturn($id);
            $file->method('getName')->willReturn('photo.jpg');
            $file->method('getMimeType')->willReturn('image/jpeg');
            $file->method('getPath')->willReturn('/alice/files/photo.jpg');
            $file->method('getEtag')->willReturn('etag');
            $file->method('isReadable')->willReturn(!in_array((string)$id, $this->denied, true));
            return [$file];
        });
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $contracts = $this->createMock(MediaLabContractService::class);
        $contracts->method('getDefaultModelContract')->willReturn(array_replace(self::CONTRACT, ['model_fingerprint' => $defaultFingerprint]));
        $media = $this->createMock(MediaLabClient::class);
        $media->expects($text ? self::once() : self::never())->method('embedText')->willReturn([
            'model_id' => 'clip', 'model_fingerprint' => $responseFingerprint, 'query_vector' => [1.0, 0.0],
        ]);
        $sessions = $this->createMock(SearchSessionRepository::class);
        $sessions->method('create')->willReturnCallback(function ($uid, $payload): array {
            self::assertSame('alice', $uid);
            $this->payload = $payload;
            return ['session_id' => self::TOKEN, 'expires_at' => time() + 900];
        });
        $sessions->method('load')->with('alice', self::TOKEN)->willReturnCallback(fn (): array => $this->payload + ['session_id' => self::TOKEN, 'expires_at' => time() + 900]);
        return new ImageSearchService($contracts, $media, new VectorValidator(), $es, $lifecycle, $root,
            $this->createMock(IURLGenerator::class), new ImageEligibilityService(), $this->createMock(ImageEmbeddingService::class), $scope, $sessions);
    }
}
