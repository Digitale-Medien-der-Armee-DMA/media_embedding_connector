<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class IndexMappingFactory
{
    public function __construct(
        private AppConfig $config,
        private IndexNameBuilder $nameBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function buildFromContract(array $contract): array
    {
        $embeddingDim = (int)($contract['embedding_dim'] ?? 0);
        $similarity = (string)($contract['similarity'] ?? 'cosine');

        return [
            'index_name' => $this->nameBuilder->buildIndexName($contract),
            'alias' => $this->config->getIndexAlias(),
            'fingerprint_guard' => [
                'model_id' => $contract['model_id'] ?? null,
                'model_version' => $contract['model_version'] ?? null,
                'model_fingerprint' => $contract['model_fingerprint'] ?? null,
                'embedding_dim' => $embeddingDim,
                'normalized' => $contract['normalized'] ?? null,
                'similarity' => $similarity,
            ],
            'mapping' => [
                'mappings' => [
                    'dynamic' => 'strict',
                    'properties' => [
                        'nextcloud_file_id' => ['type' => 'keyword'],
                        'storage_id' => ['type' => 'keyword'],
                        'etag' => ['type' => 'keyword'],
                        'mime_type' => ['type' => 'keyword'],
                        'size_bytes' => ['type' => 'long'],
                        'mtime' => ['type' => 'date'],
                        'image_vector' => [
                            'type' => 'dense_vector',
                            'dims' => $embeddingDim,
                            'index' => true,
                            'similarity' => $similarity,
                        ],
                        'model_id' => ['type' => 'keyword'],
                        'model_name' => ['type' => 'keyword'],
                        'model_version' => ['type' => 'keyword'],
                        'model_fingerprint' => ['type' => 'keyword'],
                        'embedding_dim' => ['type' => 'integer'],
                        'normalized' => ['type' => 'boolean'],
                        'similarity' => ['type' => 'keyword'],
                        'technical_metadata' => ['type' => 'object', 'enabled' => false],
                        'indexed_at' => ['type' => 'date'],
                        'embedding_request_id' => ['type' => 'keyword'],
                    ],
                ],
            ],
        ];
    }
}
