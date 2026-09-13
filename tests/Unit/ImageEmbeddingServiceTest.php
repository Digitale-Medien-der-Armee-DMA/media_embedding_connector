<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\ImageEligibilityService;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingService;
use OCA\MediaEmbeddingConnector\Service\MediaLabClient;
use OCA\MediaEmbeddingConnector\Service\MediaLabContractService;
use OCA\MediaEmbeddingConnector\Service\VectorValidator;
use OCP\Files\IMimeTypeDetector;
use PHPUnit\Framework\TestCase;

class ImageEmbeddingServiceTest extends TestCase
{
    private string $imagePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nc-upload-search-');
        self::assertIsString($path);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);
        file_put_contents($path, $png);
        $this->imagePath = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->imagePath) && is_file($this->imagePath)) {
            unlink($this->imagePath);
        }
    }

    public function testUploadedImageReturnsValidatedVector(): void
    {
        $client = $this->createMock(MediaLabClient::class);
        $client->expects(self::once())
            ->method('embedImageFile')
            ->with($this->imagePath, 'image/png')
            ->willReturn(['image_vector' => [1.0, 0.0]]);

        $service = $this->service($client, 'image/png');

        self::assertSame([1.0, 0.0], $service->embedUploadedFile([
            'tmp_name' => $this->imagePath,
            'error' => UPLOAD_ERR_OK,
        ]));
    }

    public function testUnsupportedContentIsRejectedBeforeMediaLabCall(): void
    {
        $client = $this->createMock(MediaLabClient::class);
        $client->expects(self::never())->method('embedImageFile');
        $service = $this->service($client, 'image/heic');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported_image_type');
        $service->embedUploadedFile(['tmp_name' => $this->imagePath]);
    }

    public function testInvalidMediaLabVectorIsRejected(): void
    {
        $client = $this->createMock(MediaLabClient::class);
        $client->method('embedImageFile')->willReturn(['image_vector' => [1.0]]);
        $service = $this->service($client, 'image/png');

        try {
            $service->embedUploadedFile(['tmp_name' => $this->imagePath]);
            self::fail('Expected invalid vector rejection.');
        } catch (ExternalServiceException $e) {
            self::assertSame('invalid_image_vector', $e->getPublicCode());
        }
    }

    private function service(MediaLabClient $client, string $detectedMime): ImageEmbeddingService
    {
        $contracts = $this->createMock(MediaLabContractService::class);
        $contracts->method('getDefaultModelContract')->willReturn([
            'embedding_dim' => 2,
            'normalized' => true,
            'image_input' => [
                'supported_image_mime_types' => ['image/jpeg', 'image/png'],
                'max_upload_mb' => 10,
                'max_pixels' => 1000,
            ],
        ]);
        $mimeDetector = $this->createMock(IMimeTypeDetector::class);
        $mimeDetector->method('detectContent')->with($this->imagePath)->willReturn($detectedMime);

        return new ImageEmbeddingService(
            $client,
            $contracts,
            new ImageEligibilityService(),
            new VectorValidator(),
            $mimeDetector,
        );
    }
}
