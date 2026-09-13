<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\ContractValidator;
use PHPUnit\Framework\TestCase;

class ContractValidatorTest extends TestCase
{
    public function testValidContractIsAccepted(): void
    {
        $validator = new ContractValidator();
        self::assertSame([], $validator->validateDefaultModelContract([
            'model_id' => 'clip',
            'model_name' => 'CLIP',
            'model_version' => '1',
            'model_fingerprint' => 'sha256:test',
            'embedding_dim' => 3,
            'normalized' => true,
            'similarity' => 'cosine',
            'operations' => ['embed_text', 'embed_image'],
            'text_input' => ['max_chars' => 2000],
            'image_input' => [
                'max_upload_mb' => 20,
                'max_pixels' => 40_000_000,
                'supported_image_mime_types' => ['image/jpeg'],
            ],
        ]));
    }
}
