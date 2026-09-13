<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\IndexNameBuilder;
use PHPUnit\Framework\TestCase;

class IndexNameBuilderTest extends TestCase
{
    public function testNameContainsModelDimensionAndFingerprint(): void
    {
        $name = (new IndexNameBuilder())->buildIndexName([
            'model_id' => 'OpenAI/CLIP',
            'embedding_dim' => 768,
            'model_fingerprint' => 'abcdef0123456789ffff',
        ]);

        self::assertSame('nc_media_embeddings_openai_clip_768_abcdef0123456789_v1', $name);
    }
}
