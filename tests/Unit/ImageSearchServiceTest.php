<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\ElasticsearchClient;
use OCA\MediaEmbeddingConnector\Service\ImageEligibilityService;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingService;
use OCA\MediaEmbeddingConnector\Service\ImageSearchService;
use OCA\MediaEmbeddingConnector\Service\IndexLifecycleService;
use OCA\MediaEmbeddingConnector\Service\MediaLabClient;
use OCA\MediaEmbeddingConnector\Service\MediaLabContractService;
use OCA\MediaEmbeddingConnector\Service\VectorValidator;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ImageSearchServiceTest extends TestCase
{
    public function testDeepPageCanRequestMoreThanFiveHundredCandidates(): void
    {
        $elasticsearch = $this->createMock(ElasticsearchClient::class);
        $elasticsearch->expects(self::once())
            ->method('search')
            ->with('nc_media_embeddings_search', [1.0, 0.0], 5500, null)
            ->willReturn([]);
        $lifecycle = $this->createMock(IndexLifecycleService::class);
        $lifecycle->method('getSearchAlias')->willReturn('nc_media_embeddings_search');

        $service = new ImageSearchService(
            $this->createMock(MediaLabContractService::class),
            $this->createMock(MediaLabClient::class),
            $this->createMock(VectorValidator::class),
            $elasticsearch,
            $lifecycle,
            $this->createMock(IRootFolder::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ImageEligibilityService::class),
            $this->createMock(ImageEmbeddingService::class),
        );

        $method = new \ReflectionMethod($service, 'searchVector');
        $result = $method->invoke($service, 'alice', [1.0, 0.0], 100, 1000);

        self::assertSame([], $result['results']);
        self::assertSame(1000, $result['offset']);
        self::assertSame(100, $result['limit']);
        self::assertFalse($result['has_more']);
    }
}
