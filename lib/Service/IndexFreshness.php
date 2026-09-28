<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

/**
 * Decides whether a file's stored indexing outcome still matches its current
 * content and the active model, so it can be left alone instead of being sent
 * to the Media Embedding Service again.
 */
final class IndexFreshness
{
    /**
     * @param array<string, mixed>|null $indexed row from media_embed_idx_files
     * @param array<string, mixed>|null $skip row from media_embed_skips
     */
    public static function isCurrent(
        ?array $indexed,
        ?array $skip,
        string $etag,
        string $modelFingerprint,
        string $writeIndex,
    ): bool {
        if ($etag === '' || $modelFingerprint === '') {
            return false;
        }

        if (
            $indexed !== null
            && (string)($indexed['etag'] ?? '') === $etag
            && (string)($indexed['model_fingerprint'] ?? '') === $modelFingerprint
            && $writeIndex !== ''
            && (string)($indexed['index_name'] ?? '') === $writeIndex
        ) {
            return true;
        }

        return $skip !== null
            && (string)($skip['etag'] ?? '') === $etag
            && (string)($skip['model_fingerprint'] ?? '') === $modelFingerprint;
    }
}
