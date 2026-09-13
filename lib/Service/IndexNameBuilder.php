<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class IndexNameBuilder
{
    /**
     * @param array<string, mixed> $contract
     */
    public function buildIndexName(array $contract): string
    {
        $modelId = $this->slug((string)($contract['model_id'] ?? 'unknown'));
        $dim = (int)($contract['embedding_dim'] ?? 0);
        $fingerprint = $this->slug(substr((string)($contract['model_fingerprint'] ?? 'unknown'), 0, 16));

        return 'nc_media_embeddings_' . $modelId . '_' . $dim . '_' . $fingerprint . '_v1';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?: 'unknown');
        return trim($slug, '_') ?: 'unknown';
    }
}
