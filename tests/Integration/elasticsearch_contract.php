<?php

declare(strict_types=1);

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
        'properties' => [
            'nextcloud_file_id' => ['type' => 'keyword'],
            'image_vector' => [
                'type' => 'dense_vector',
                'dims' => 3,
                'index' => true,
                'similarity' => 'cosine',
            ],
        ],
    ],
]);
request('PUT', $baseUrl . '/' . $index . '/_doc/1?refresh=true', [
    'nextcloud_file_id' => '1',
    'image_vector' => [1.0, 0.0, 0.0],
]);
request('PUT', $baseUrl . '/' . $index . '/_doc/2?refresh=true', [
    'nextcloud_file_id' => '2',
    'image_vector' => [0.9, 0.1, 0.0],
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

request('DELETE', $baseUrl . '/' . $index);
echo "Elasticsearch contract test passed.\n";
