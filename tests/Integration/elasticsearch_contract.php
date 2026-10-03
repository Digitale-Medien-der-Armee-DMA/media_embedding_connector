<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/NextcloudStubs.php';
require_once dirname(__DIR__, 2) . '/lib/Exception/ExternalServiceException.php';
require_once dirname(__DIR__, 2) . '/lib/Service/ConnectionConfigService.php';
require_once dirname(__DIR__, 2) . '/lib/Service/ElasticsearchClient.php';

$baseUrl = rtrim(getenv('ELASTICSEARCH_URL') ?: 'http://127.0.0.1:9200', '/');
$index = 'nc_media_embeddings_ci_3_contract_v1';
$alias = 'nc_media_embeddings_ci_current';

function request(string $method, string $url, ?array $body = null): array
{
    $options = [
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        ],
    ];
    $raw = file_get_contents($url, false, stream_context_create($options));
    if ($raw === false) {
        throw new RuntimeException('Elasticsearch request failed: ' . $url);
    }
    $decoded = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

request('DELETE', $baseUrl . '/' . $index);
request('PUT', $baseUrl . '/' . $index, [
    'mappings' => [
        'dynamic' => 'strict',
        '_meta' => ['embedding_contract' => ['model_id' => 'clip', 'model_fingerprint' => 'fp', 'embedding_dim' => 3, 'normalized' => true, 'similarity' => 'cosine']],
        'properties' => [
            'nextcloud_file_id' => ['type' => 'keyword'],
            'model_fingerprint' => ['type' => 'keyword'],
            'image_vector' => [
                'type' => 'dense_vector',
                'dims' => 3,
                'index' => true,
                'index_options' => ['type' => 'hnsw'],
                'similarity' => 'cosine',
            ],
        ],
    ],
]);
request('PUT', $baseUrl . '/' . $index . '/_doc/1?refresh=true', [
    'nextcloud_file_id' => '1',
    'model_fingerprint' => 'fp',
    'image_vector' => [1.0, 0.0, 0.0],
]);
request('PUT', $baseUrl . '/' . $index . '/_doc/2?refresh=true', [
    'nextcloud_file_id' => '2',
    'model_fingerprint' => 'fp',
    'image_vector' => [0.9, 0.1, 0.0],
]);
request('PUT', $baseUrl . '/' . $index . '/_doc/3?refresh=true', [
    'nextcloud_file_id' => '3',
    'model_fingerprint' => 'fp',
    'image_vector' => [0.8, 0.2, 0.0],
]);
request('POST', $baseUrl . '/_aliases', [
    'actions' => [['add' => ['index' => $index, 'alias' => $alias]]],
]);
$search = request('POST', $baseUrl . '/' . $alias . '/_search', [
    'size' => 2,
    '_source' => false,
    'knn' => [
        'field' => 'image_vector',
        'query_vector' => [1.0, 0.0, 0.0],
        'k' => 2,
        'num_candidates' => 10,
    ],
]);

$hits = $search['hits']['hits'] ?? [];
if (!is_array($hits) || ($hits[0]['_id'] ?? null) !== '1') {
    throw new RuntimeException('Elasticsearch kNN contract test failed.');
}

// The strongest global match is unauthorized. With a kNN pre-filter,
// authorized images must fill the result window instead of disappearing.
$filtered = request('POST', $baseUrl . '/' . $alias . '/_search', [
    'size' => 49,
    '_source' => false,
    'knn' => [
        'field' => 'image_vector',
        'query_vector' => [1.0, 0.0, 0.0],
        'k' => 49,
        'num_candidates' => 10000,
        'filter' => ['bool' => ['filter' => [['ids' => ['values' => ['2', '3']]]]]],
    ],
]);
if (array_column($filtered['hits']['hits'] ?? [], '_id') !== ['2', '3']) {
    throw new RuntimeException('Elasticsearch permission pre-filter contract failed.');
}


// Exercise the production client rather than just equivalent handwritten JSON.
$connection = new class($baseUrl) extends \OCA\MediaEmbeddingConnector\Service\ConnectionConfigService {
    public function __construct(private string $url) {}
    public function getElasticsearchConnection(array $overrides = []): array {
        return ['url' => $this->url, 'username' => '', 'password' => '', 'api_key' => '', 'verify_tls' => true, 'allow_private_networks' => true];
    }
};
$http = new class implements \OCP\Http\Client\IClientService {
    public function newClient() {
        return new class implements \OCP\Http\Client\IClient {
            public function request(string $method, string $url, array $options = []) {
                $raw = file_get_contents($url, false, stream_context_create(['http' => [
                    'method' => $method, 'ignore_errors' => true, 'header' => 'Content-Type: application/json',
                    'content' => $options['body'] ?? '',
                ]]));
                if ($raw === false) { throw new RuntimeException('HTTP request failed'); }
                preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $status);
                return new class($raw, (int)($status[1] ?? 0)) implements \OCP\Http\Client\IResponse {
                    public function __construct(private string $body, private int $status) {}
                    public function getStatusCode() { return $this->status; }
                    public function getBody() { return $this->body; }
                };
            }
        };
    }
};
$client = new \OCA\MediaEmbeddingConnector\Service\ElasticsearchClient($connection, $http);
if ($client->resolveSearchIndex($alias) !== $index || ($client->getIndexContract($index)['model_fingerprint'] ?? null) !== 'fp') {
    throw new RuntimeException('Pinned index contract could not be resolved');
}
request('PUT', $baseUrl . '/' . $index . '/_doc/4?refresh=true', [
    'nextcloud_file_id' => '4', 'model_fingerprint' => 'wrong', 'image_vector' => [1.0, 0.0, 0.0],
]);
$pit = $client->openSearchSnapshot($index);
try {
    // New index writes must not change a ranking part way through scope blocks.
    request('PUT', $baseUrl . '/' . $index . '/_doc/5?refresh=true', [
        'nextcloud_file_id' => '5', 'model_fingerprint' => 'fp', 'image_vector' => [1.0, 0.0, 0.0],
    ]);
    $ann = $client->search($index, [1.0, 0.0, 0.0], 49, ['2', '3', '4', '5'], null, false, 'fp', $pit);
    $exact = $client->search($index, [1.0, 0.0, 0.0], 49, ['2', '3', '4', '5'], null, true, 'fp', $pit);
    if (array_column($ann, 'file_id') !== ['2', '3'] || array_column($exact, 'file_id') !== ['2', '3']) {
        throw new RuntimeException('Production ANN/exact permission, model or PIT filter failed');
    }
    if (abs($ann[0]['score'] - $exact[0]['score']) > 0.00001) {
        throw new RuntimeException('Exact and ANN cosine scores use different scales');
    }
    $excluded = $client->search($index, [1.0, 0.0, 0.0], 49, ['2', '3'], '3', true, 'fp', $pit);
    if (array_column($excluded, 'file_id') !== ['2']) {
        throw new RuntimeException('Reference exclusion failed');
    }
} finally {
    $client->closeSearchSnapshot($pit);
}
request('PUT', $baseUrl . '/' . $index . '/_mapping', ['_meta' => new stdClass()]);
if (($client->getIndexContract($index)['model_fingerprint'] ?? null) !== 'fp') {
    throw new RuntimeException('Legacy index contract fallback failed');
}
try {
    $client->getDocumentVector($index, '4', 'fp');
    throw new RuntimeException('Mismatched reference vector was accepted');
} catch (\OCA\MediaEmbeddingConnector\Exception\ExternalServiceException $e) {
    if ($e->getPublicCode() !== 'search_model_mismatch') { throw $e; }
}
request('DELETE', $baseUrl . '/' . $index);
echo "Elasticsearch contract test passed.\n";
