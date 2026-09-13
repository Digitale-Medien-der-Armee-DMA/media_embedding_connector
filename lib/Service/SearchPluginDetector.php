<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCP\App\IAppManager;

class SearchPluginDetector
{
    private const KNOWN_APPS = [
        'fulltextsearch' => ['name' => 'Full Text Search', 'role' => 'search_framework'],
        'fulltextsearch_elasticsearch' => ['name' => 'Full Text Search - Elasticsearch Platform', 'role' => 'elasticsearch_backend'],
        'files_fulltextsearch' => ['name' => 'Files Full Text Search', 'role' => 'files_provider'],
        'files_fulltextsearch_tesseract' => ['name' => 'Files Full Text Search - Tesseract OCR', 'role' => 'ocr_provider'],
    ];

    public function __construct(
        private IAppManager $appManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function detect(): array
    {
        $apps = [];
        foreach (self::KNOWN_APPS as $appId => $meta) {
            $apps[$appId] = [
                'app_id' => $appId,
                'name' => $meta['name'],
                'role' => $meta['role'],
                'installed' => $this->appManager->isInstalled($appId),
                'enabled' => $this->appManager->isEnabledForAnyone($appId),
            ];
        }

        return ['known_apps' => $apps];
    }
}
