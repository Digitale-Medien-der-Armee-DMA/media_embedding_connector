<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Http\Client\IClientService;

class ElasticsearchClient
{
    public const MINIMUM_VERSION = '8.12.0';
    private const MANAGED_INDEX_PREFIX = 'nc_media_embeddings_';

    public function __construct(
        private ConnectionConfigService $connectionConfig,
        private IClientService $clientService,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function testConnection(array $overrides = []): array
    {
        $started = hrtime(true);
        $response = $this->request('GET', '/', null, $overrides);
        $version = (string)($response['version']['number'] ?? '');
        if ($version === '' || version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new ExternalServiceException(
                'Elasticsearch 8.12 or newer is required.',
                'elasticsearch_version_unsupported',
            );
        }

        return [
            'success' => true,
            'version' => $version,
            'cluster_name' => $response['cluster_name'] ?? null,
            'distribution' => $response['version']['build_flavor'] ?? 'default',
            'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function createIndex(string $indexName, array $mapping): void
    {
        $this->assertManagedIndexName($indexName);
        $this->request('PUT', '/' . rawurlencode($indexName), $mapping);
    }

    public function indexExists(string $indexName): bool
    {
        $this->assertManagedIndexName($indexName);
        try {
            $this->request('HEAD', '/' . rawurlencode($indexName));
            return true;
        } catch (ExternalServiceException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    public function upsertDocument(string $indexName, string $fileId, array $document): void
    {
        $this->assertManagedIndexName($indexName);
        $this->request(
            'PUT',
            '/' . rawurlencode($indexName) . '/_doc/' . rawurlencode($fileId),
            $document,
        );
    }

    public function deleteDocument(string $indexOrAlias, string $fileId): void
    {
        $this->assertManagedIndexName($indexOrAlias, true);
        try {
            $this->request(
                'DELETE',
                '/' . rawurlencode($indexOrAlias) . '/_doc/' . rawurlencode($fileId),
            );
        } catch (ExternalServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * @return list<float>|null
     */
    public function getDocumentVector(string $indexOrAlias, string $fileId): ?array
    {
        $this->assertManagedIndexName($indexOrAlias, true);
        try {
            $response = $this->request(
                'GET',
                '/' . rawurlencode($indexOrAlias) . '/_source/' . rawurlencode($fileId)
                    . '?_source_includes=image_vector',
            );
        } catch (ExternalServiceException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }

        $vector = $response['image_vector'] ?? null;
        if (!is_array($vector)) {
            return null;
        }

        return array_values(array_map(static fn (mixed $value): float => (float)$value, $vector));
    }

    /**
     * @param list<float|int> $vector
     * @return list<array{file_id:string, score:float}>
     */
    public function search(
        string $indexOrAlias,
        array $vector,
        int $limit,
        ?string $excludeFileId = null,
    ): array {
        $this->assertManagedIndexName($indexOrAlias, true);
        $limit = max(1, min(500, $limit));
        $k = $excludeFileId === null ? $limit : $limit + 1;
        $response = $this->request('POST', '/' . rawurlencode($indexOrAlias) . '/_search', [
            'size' => $k,
            '_source' => false,
            'knn' => [
                'field' => 'image_vector',
                'query_vector' => $vector,
                'k' => $k,
                'num_candidates' => max(100, min(10_000, $k * 10)),
            ],
        ]);

        $results = [];
        $hits = is_array($response['hits']['hits'] ?? null) ? $response['hits']['hits'] : [];
        foreach ($hits as $hit) {
            $fileId = is_string($hit['_id'] ?? null) ? $hit['_id'] : '';
            if ($fileId === '' || $fileId === $excludeFileId) {
                continue;
            }
            $results[] = [
                'file_id' => $fileId,
                'score' => (float)($hit['_score'] ?? 0.0),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function swapAlias(string $alias, string $newIndex): void
    {
        $this->assertManagedIndexName($alias, true);
        $this->assertManagedIndexName($newIndex);
        $actions = [];
        try {
            $aliases = $this->request('GET', '/_alias/' . rawurlencode($alias));
            foreach (array_keys($aliases) as $oldIndex) {
                if (is_string($oldIndex) && $oldIndex !== $newIndex) {
                    $actions[] = ['remove' => ['index' => $oldIndex, 'alias' => $alias]];
                }
            }
        } catch (ExternalServiceException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $actions[] = ['add' => ['index' => $newIndex, 'alias' => $alias]];
        $this->request('POST', '/_aliases', [
            'actions' => $actions,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listManagedIndices(): array
    {
        $response = $this->request(
            'GET',
            '/_cat/indices/' . self::MANAGED_INDEX_PREFIX
                . '*?format=json&h=index,docs.count,store.size,creation.date.string',
        );

        return array_is_list($response) ? $response : [];
    }

    public function deleteManagedIndex(string $indexName): void
    {
        $this->assertManagedIndexName($indexName);
        $this->request('DELETE', '/' . rawurlencode($indexName));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $overrides = [],
    ): array {
        $connection = $this->connectionConfig->getElasticsearchConnection($overrides);
        $url = rtrim($connection['url'], '/');
        if ($url === '') {
            throw new ExternalServiceException(
                'Elasticsearch is not configured.',
                'elasticsearch_not_configured',
            );
        }

        $headers = ['Accept' => 'application/json'];
        if ($connection['api_key'] !== '') {
            $headers['Authorization'] = 'ApiKey ' . $connection['api_key'];
        }

        $options = [
            'timeout' => 30,
            'connect_timeout' => 5,
            'http_errors' => false,
            'allow_redirects' => false,
            'verify' => $connection['verify_tls'],
            'nextcloud' => ['allow_local_address' => $connection['allow_private_networks']],
            'headers' => $headers,
        ];
        if ($connection['api_key'] === '' && $connection['username'] !== '') {
            $options['auth'] = [$connection['username'], $connection['password']];
        }
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        try {
            $response = $this->clientService->newClient()->request(strtoupper($method), $url . $path, $options);
        } catch (\Throwable $e) {
            throw new ExternalServiceException(
                'Elasticsearch request could not be completed.',
                'elasticsearch_unreachable',
                true,
                null,
                0,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $rawBody = trim((string)$response->getBody());
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorType = 'elasticsearch_error';
            if (is_array($decoded)) {
                if (is_string($decoded['error'] ?? null)) {
                    $errorType = $decoded['error'];
                } elseif (is_array($decoded['error'] ?? null) && is_string($decoded['error']['type'] ?? null)) {
                    $errorType = $decoded['error']['type'];
                }
            }
            throw new ExternalServiceException(
                'Elasticsearch rejected the request.',
                $errorType,
                $statusCode === 429 || $statusCode >= 500,
                null,
                $statusCode,
            );
        }

        if ($decoded === null || !is_array($decoded)) {
            throw new ExternalServiceException(
                'Elasticsearch returned an invalid response.',
                'elasticsearch_invalid_response',
                false,
                null,
                $statusCode,
            );
        }

        return $decoded;
    }

    private function assertManagedIndexName(string $name, bool $allowAlias = false): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,254}$/', $name)) {
            throw new \InvalidArgumentException('Invalid Elasticsearch index name.');
        }
        if (!$allowAlias && !str_starts_with($name, self::MANAGED_INDEX_PREFIX)) {
            throw new \InvalidArgumentException('Only connector-managed indices are allowed.');
        }
    }
}
