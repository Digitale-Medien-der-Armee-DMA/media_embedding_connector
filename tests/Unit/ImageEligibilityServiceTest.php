<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageEligibilityService;
use PHPUnit\Framework\TestCase;

class ImageEligibilityServiceTest extends TestCase
{
    private ImageEligibilityService $service;

    protected function setUp(): void
    {
        $this->service = new ImageEligibilityService();
    }

    public function testJpegIsAllowedWhenMediaLabContractSupportsIt(): void
    {
        $result = $this->service->evaluate('image/jpeg', 1024, 1000, $this->contract(['image/jpeg']));

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    public function testJpegFamilyAliasesAreTreatedAsJpegCandidates(): void
    {
        $result = $this->service->evaluate('image/mpo', 1024, 1000, $this->contract(['image/jpeg']));

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
        self::assertTrue($this->service->isResultSupportedMimeType('image/mpo'));
        self::assertSame('image/jpeg', ImageEligibilityService::normalizeMimeType('image/mpo'));
    }

    public function testAllowedImageExtensionsAreIndexingCandidatesWhenMimeIsGeneric(): void
    {
        self::assertTrue($this->service->isIndexingCandidate('application/octet-stream', 'IMG_7229.jpeg'));
        self::assertTrue($this->service->isIndexingCandidate(null, 'scan.JPG'));
        self::assertTrue($this->service->isIndexingCandidate('application/octet-stream', 'still.webp'));
        self::assertFalse($this->service->isIndexingCandidate('application/octet-stream', 'movie.webm'));
    }

    public function testDisabledMimeTypeIsNotAllowedByImageExtension(): void
    {
        self::assertFalse($this->service->isIndexingCandidate('image/heic', 'renamed.jpeg'));
    }

    /**
     * @dataProvider unsupportedMimeTypeProvider
     */
    public function testUnsupportedNextcloudResultMimeTypesAreRejected(string $mimeType): void
    {
        $result = $this->service->evaluate(
            $mimeType,
            1024,
            1000,
            $this->contract([$mimeType, 'image/jpeg']),
        );

        self::assertFalse($result['allowed']);
        self::assertSame(SkipMarkerRepository::REASON_UNSUPPORTED_IMAGE_TYPE, $result['reason']);
        self::assertFalse($this->service->isResultSupportedMimeType($mimeType));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsupportedMimeTypeProvider(): iterable
    {
        yield 'heic' => ['image/heic'];
        yield 'heif' => ['image/heif'];
        yield 'nef' => ['image/x-nikon-nef'];
        yield 'raw' => ['image/x-dcraw'];
        yield 'psd' => ['image/vnd.adobe.photoshop'];
        yield 'tiff' => ['image/tiff'];
        yield 'bmp' => ['image/bmp'];
    }

    public function testAdminConfiguredListsOverrideDefaults(): void
    {
        $config = new class extends AppConfig {
            // phpcs:ignore — bypass the IAppConfig dependency for a pure unit test.
            public function __construct()
            {
            }

            public function getAllowedImageMimeTypes(): array
            {
                return ['image/jpeg'];
            }

            public function getDisabledImageMimeTypes(): array
            {
                return ['image/png'];
            }
        };
        $service = new ImageEligibilityService($config);

        self::assertTrue($service->isResultSupportedMimeType('image/jpeg'));
        // Removed from the allow list -> no longer supported.
        self::assertFalse($service->isResultSupportedMimeType('image/webp'));
        // Explicitly disabled by the admin even though it is a common format.
        self::assertFalse($service->isResultSupportedMimeType('image/png'));
    }

    public function testSplitMimeListNormalisesSeparatorsCaseAndDuplicates(): void
    {
        self::assertSame(
            ['image/jpeg', 'image/png', 'image/webp'],
            AppConfig::splitMimeList(" Image/JPEG, image/png\n image/webp , image/jpeg "),
        );
        self::assertSame([], AppConfig::splitMimeList('   '));
    }

    /**
     * @param list<string> $mimeTypes
     * @return array<string, mixed>
     */
    private function contract(array $mimeTypes): array
    {
        return [
            'image_input' => [
                'supported_image_mime_types' => $mimeTypes,
                'max_upload_mb' => 10,
                'max_pixels' => 1000000,
            ],
        ];
    }
}
