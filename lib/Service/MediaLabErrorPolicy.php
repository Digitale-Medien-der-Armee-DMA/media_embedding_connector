<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;

class MediaLabErrorPolicy
{
    /**
     * @return array<string, mixed>
     */
    public function classify(int $statusCode, string $errorCode): array
    {
        if (in_array($statusCode, [429, 503, 504], true)) {
            return [
                'category' => 'retryable',
                'retry' => true,
                'skip_reason' => null,
            ];
        }

        $skipReason = match ($errorCode) {
            SkipMarkerRepository::REASON_IMAGE_TOO_LARGE => SkipMarkerRepository::REASON_IMAGE_TOO_LARGE,
            SkipMarkerRepository::REASON_IMAGE_PIXEL_LIMIT_EXCEEDED => SkipMarkerRepository::REASON_IMAGE_PIXEL_LIMIT_EXCEEDED,
            SkipMarkerRepository::REASON_UNSUPPORTED_IMAGE_TYPE => SkipMarkerRepository::REASON_UNSUPPORTED_IMAGE_TYPE,
            SkipMarkerRepository::REASON_INVALID_IMAGE => SkipMarkerRepository::REASON_INVALID_IMAGE,
            default => null,
        };

        if ($skipReason !== null) {
            return [
                'category' => 'permanent_skip',
                'retry' => false,
                'skip_reason' => $skipReason,
            ];
        }

        if (in_array($statusCode, [401, 403], true)) {
            return [
                'category' => 'configuration_error',
                'retry' => false,
                'skip_reason' => null,
            ];
        }

        if (in_array($statusCode, [400, 413, 415, 422], true)) {
            return [
                'category' => 'permanent_error',
                'retry' => false,
                'skip_reason' => null,
            ];
        }

        return [
            'category' => $statusCode >= 500 ? 'retryable' : 'permanent_error',
            'retry' => $statusCode >= 500,
            'skip_reason' => null,
        ];
    }
}
