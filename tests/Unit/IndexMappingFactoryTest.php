<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\IndexMappingFactory;
use OCA\MediaEmbeddingConnector\Service\IndexNameBuilder;
use PHPUnit\Framework\TestCase;

class IndexMappingFactoryTest extends TestCase
{
    public function testMappingAllowsTechnicalMetadataUnderStrictDynamicMode(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getIndexAlias')->willReturn(AppConfig::DEFAULT_INDEX_ALIAS);
        $mapping = (new IndexMappingFactory($config, new IndexNameBuilder()))->buildFromContract([
            'model_id' => 'clip',
            'model_version' => '1',
            'model_fingerprint' => 'abcdef0123456789',
            'embedding_dim' => 768,
            'normalized' => true,
            'similarity' => 'cosine',
        ]);

        $properties = $mapping['mapping']['mappings']['properties'];

        self::assertSame('strict', $mapping['mapping']['mappings']['dynamic']);
        self::assertSame(
            ['type' => 'object', 'enabled' => false],
            $properties['technical_metadata'],
        );
    }
}
