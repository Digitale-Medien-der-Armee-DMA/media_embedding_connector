<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'admin#save',
            'url' => '/settings',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#probe',
            'url' => '/settings/probe',
            'verb' => 'GET',
        ],
        [
            'name' => 'admin#testMediaLab',
            'url' => '/settings/test-medialab',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#testElasticsearch',
            'url' => '/settings/test-elasticsearch',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#status',
            'url' => '/settings/status',
            'verb' => 'GET',
        ],
        [
            'name' => 'admin#setIndexingEnabled',
            'url' => '/settings/indexing',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#prepareIndex',
            'url' => '/settings/index/prepare',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#activateIndex',
            'url' => '/settings/index/activate',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#startBackfill',
            'url' => '/settings/backfill/start',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#setBackfillPaused',
            'url' => '/settings/backfill/pause',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#retryJobs',
            'url' => '/settings/jobs/retry',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#deleteIndex',
            'url' => '/settings/indices/delete',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#probeImage',
            'url' => '/settings/probe-image',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#probeText',
            'url' => '/settings/probe-text',
            'verb' => 'POST',
        ],
        [
            'name' => 'admin#resetSkipMarkers',
            'url' => '/settings/skip-markers/reset',
            'verb' => 'POST',
        ],
        [
            'name' => 'search#index',
            'url' => '/',
            'verb' => 'GET',
        ],
        [
            'name' => 'search#search',
            'url' => '/api/search',
            'verb' => 'POST',
        ],
        [
            'name' => 'search#image',
            'url' => '/api/search/image',
            'verb' => 'POST',
        ],
        [
            'name' => 'search#similar',
            'url' => '/api/similar/{fileId}',
            'verb' => 'POST',
        ],
    ],
];
