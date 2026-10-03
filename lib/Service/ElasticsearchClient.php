<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Http\Client\IClientService;

class ElasticsearchClient
{
    public const MINIMUM_VERSION = '8.12.0';
    private const MANAGED_INDEX_PREFIX = 'nc_media_embeddings_';
    private const MAX_KNN_RESULTS = 5_500;
    private const NUM_KNN_CANDIDATES = 10_000;

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
    public function getDocumentVector(string $indexOrAlias, string $fileId, ?string $modelFingerprint = null): ?array
    {
        $this->assertManagedIndexName($indexOrAlias, true);
        try {
            $response = $this->request(
                'GET',
                '/' . rawurlencode($indexOrAlias) . '/_source/' . rawurlencode($fileId)
                    . '?_source_includes=image_vector,model_fingerprint',
            );
        } catch (ExternalServiceException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }

        if ($modelFingerprint !== null && ($response['model_fingerprint'] ?? null) !== $modelFingerprint) {
            throw new ExternalServiceException('Reference image model differs from the search index.', 'search_model_mismatch');
        }
        $vector = $response['image_vector'] ?? null;
        if (!is_array($vector)) {
            return null;
        }

        return array_values(array_map(static fn (mixed $value): float => (float)$value, $vector));
    }

    public function resolveSearchIndex(string $alias): string
    {
        $this->assertManagedIndexName($alias, true);
        $indices = array_keys($this->request('GET', '/_alias/' . rawurlencode($alias)));
        if (count($indices) !== 1 || !is_string($indices[0])) {
            throw new ExternalServiceException('Search alias must point to one index.', 'search_index_ambiguous');
        }
        $this->assertManagedIndexName($indices[0]);
        return $indices[0];
    }

    /** @return array<string, mixed> */
    public function getIndexContract(string $index): array
    {
        $this->assertManagedIndexName($index);
        $mapping = $this->request('GET', '/' . rawurlencode($index) . '/_mapping');
        $contract = $mapping[$index]['mappings']['_meta']['embedding_contract'] ?? null;
        if (is_array($contract)) {
            return $contract;
        }
        // Existing indices predate mapping metadata. Their documents already
        // carry the full vector identity; do not infer it from the write index.
        $response = $this->request('POST', '/' . rawurlencode($index) . '/_search', [
            'size' => 1,
            '_source' => ['model_id', 'model_fingerprint', 'embedding_dim', 'normalized', 'similarity'],
            'query' => ['match_all' => new \stdClass()],
        ]);
        $this->assertCompleteSearch($response);
        $source = $response['hits']['hits'][0]['_source'] ?? [];
        return is_array($source) ? $source : [];
    }

    public function openSearchSnapshot(string $index): string
    {
        $this->assertManagedIndexName($index);
        $response = $this->request('POST', '/' . rawurlencode($index)
            . '/_pit?keep_alive=2m');
        $this->assertCompleteSearch($response);
        $id = $response['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new ExternalServiceException('Search snapshot is unavailable.', 'search_incomplete', true);
        }
        return $id;
    }

    public function closeSearchSnapshot(string $id): void
    {
        $this->request('DELETE', '/_pit', ['id' => $id]);
    }

    /**
     * @param list<float|int> $vector
     * @param list<string> $fileIds Explicit readable scope, at most 50000 IDs.
     * @return list<array{file_id:string, score:float}>
     */
    public function search(
        string $indexOrAlias,
        array $vector,
        int $limit,
        array $fileIds,
        ?string $excludeFileId = null,
        bool $exact = false,
        ?string $modelFingerprint = null,
        ?string &$snapshotId = null,
    ): array {
        $this->assertManagedIndexName($indexOrAlias, true);
        if ($fileIds === []) {
            return [];
        }
        if (count($fileIds) > 50000) {
            throw new \InvalidArgumentException('Search scope must be batched.');
        }
        $limit = max(1, min(self::MAX_KNN_RESULTS, $limit));
        $k = $limit;
        $filter = ['bool' => ['filter' => [['ids' => ['values' => $fileIds]]]]];
        if ($modelFingerprint !== null) {
            $filter['bool']['filter'][] = ['term' => ['model_fingerprint' => $modelFingerprint]];
        }
        if ($excludeFileId !== null) {
            $filter['bool']['must_not'] = [['ids' => ['values' => [$excludeFileId]]]];
        }
        $body = [
            'size' => $k,
            '_source' => false,
            'track_total_hits' => false,
            'sort' => [['_score' => 'desc'], ['nextcloud_file_id' => 'asc']],
        ];
        if ($exact) {
            $body['query'] = ['script_score' => [
                'query' => $filter,
                'script' => [
                    // Same score scale as ES cosine kNN; retain original vectors.
                    'source' => "Math.max(0.0, (cosineSimilarity(params.vector, 'image_vector') + 1.0) / 2.0)",
                    'params' => ['vector' => $vector],
                ],
            ]];
        } else {
            $body['knn'] = [
                'field' => 'image_vector',
                'query_vector' => $vector,
                'k' => $k,
                'filter' => $filter,
                'num_candidates' => self::NUM_KNN_CANDIDATES,
            ];
        }
        $path = '/' . rawurlencode($indexOrAlias) . '/_search';
        if ($snapshotId !== null) {
            $body['pit'] = ['id' => $snapshotId, 'keep_alive' => '2m'];
            $path = '/_search';
        }
        $response = $this->request('POST', $path . '?allow_partial_search_results=false', $body);
        if ($snapshotId !== null && is_string($response['pit_id'] ?? null)) {
            $snapshotId = $response['pit_id'];
        }

        $this->assertCompleteSearch($response);

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

    /** @param array<string, mixed> $response */
    private function assertCompleteSearch(array $response): void
    {
        if (($response['timed_out'] ?? false) || (int)($response['_shards']['failed'] ?? 0) > 0) {
            throw new ExternalServiceException('Vector search was incomplete.', 'search_incomplete', true);
        }
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
