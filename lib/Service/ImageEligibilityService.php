<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;

class ImageEligibilityService
{
    private const MIME_ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/mpo' => 'image/jpeg',
        'image/x-png' => 'image/png',
    ];

    private const MIME_EXTENSIONS = [
        'image/gif' => ['gif'],
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * The admin-configurable allow/deny lists live in {@see AppConfig}. When no
     * config is injected (unit tests), the shipped defaults are used.
     */
    public function __construct(private ?AppConfig $config = null)
    {
    }

    /**
     * @return list<string>
     */
    private function allowedMimeTypes(): array
    {
        return $this->config?->getAllowedImageMimeTypes() ?? AppConfig::DEFAULT_ALLOWED_IMAGE_MIME_TYPES;
    }

    /**
     * @return list<string>
     */
    private function disabledMimeTypes(): array
    {
        return $this->config?->getDisabledImageMimeTypes() ?? AppConfig::DEFAULT_DISABLED_IMAGE_MIME_TYPES;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{allowed:bool, reason:?string}
     */
    public function evaluate(?string $mimeType, ?int $sizeBytes, ?int $pixelCount, array $contract): array
    {
        $imageInput = is_array($contract['image_input'] ?? null) ? $contract['image_input'] : [];
        $supportedMimeTypes = is_array($imageInput['supported_image_mime_types'] ?? null)
            ? array_map(self::class . '::normalizeMimeType', $imageInput['supported_image_mime_types'])
            : [];
        $mimeType = self::normalizeMimeType($mimeType);

        if (!$this->isResultSupportedMimeType($mimeType)) {
            return ['allowed' => false, 'reason' => SkipMarkerRepository::REASON_UNSUPPORTED_IMAGE_TYPE];
        }

        if ($mimeType !== '' && !in_array($mimeType, $supportedMimeTypes, true)) {
            return ['allowed' => false, 'reason' => SkipMarkerRepository::REASON_UNSUPPORTED_IMAGE_TYPE];
        }

        $maxUploadMb = $imageInput['max_upload_mb'] ?? null;
        if (is_int($maxUploadMb) && $sizeBytes !== null && $sizeBytes > $maxUploadMb * 1024 * 1024) {
            return ['allowed' => false, 'reason' => SkipMarkerRepository::REASON_IMAGE_TOO_LARGE];
        }

        $maxPixels = $imageInput['max_pixels'] ?? null;
        if (is_int($maxPixels) && $pixelCount !== null && $pixelCount > $maxPixels) {
            return ['allowed' => false, 'reason' => SkipMarkerRepository::REASON_IMAGE_PIXEL_LIMIT_EXCEEDED];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function isIndexingCandidate(?string $mimeType, ?string $fileName = null): bool
    {
        if ($this->isResultSupportedMimeType($mimeType)) {
            return true;
        }

        if ($this->isDisabledMimeType($mimeType)) {
            return false;
        }

        $extension = $this->extension($fileName);
        if ($extension === null) {
            return false;
        }

        foreach ($this->allowedMimeTypes() as $allowedMimeType) {
            if (in_array($extension, self::MIME_EXTENSIONS[self::normalizeMimeType($allowedMimeType)] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function isResultSupportedMimeType(?string $mimeType): bool
    {
        if ($mimeType === null || $mimeType === '') {
            return false;
        }

        if ($this->isDisabledMimeType($mimeType)) {
            return false;
        }

        return in_array(self::normalizeMimeType($mimeType), array_map(self::class . '::normalizeMimeType', $this->allowedMimeTypes()), true);
    }

    public static function normalizeMimeType(?string $mimeType): string
    {
        $mimeType = strtolower(trim((string)$mimeType));
        return self::MIME_ALIASES[$mimeType] ?? $mimeType;
    }

    private function isDisabledMimeType(?string $mimeType): bool
    {
        $rawMimeType = strtolower(trim((string)$mimeType));
        if ($rawMimeType === '') {
            return false;
        }

        $disabled = $this->disabledMimeTypes();
        return in_array($rawMimeType, $disabled, true)
            || in_array(self::normalizeMimeType($rawMimeType), array_map(self::class . '::normalizeMimeType', $disabled), true);
    }

    private function extension(?string $fileName): ?string
    {
        $extension = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
        return $extension === '' ? null : $extension;
    }
}
