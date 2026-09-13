<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\AppInfo\Application;
use OCP\IAppConfig;

class AppConfig
{
    public const KEY_MEDIALAB_ORIGIN_URL = 'medialab_origin_url';
    public const KEY_MEDIALAB_TOKEN = 'medialab_token';
    public const KEY_MAX_PARALLEL_EMBED_REQUESTS = 'max_parallel_embed_requests';
    public const KEY_IMAGE_BATCH_SIZE = 'image_batch_size';
    public const KEY_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN = 'image_batch_max_parallel_requests_per_token';
    public const KEY_IMAGE_BATCH_REQUEST_TIMEOUT = 'image_batch_request_timeout';
    public const KEY_INDEX_ALIAS = 'index_alias';
    public const KEY_INDEXING_ENABLED = 'indexing_enabled';
    public const KEY_ELASTICSEARCH_CONFIG_SOURCE = 'elasticsearch_config_source';
    public const KEY_ELASTICSEARCH_URL = 'elasticsearch_url';
    public const KEY_ELASTICSEARCH_USERNAME = 'elasticsearch_username';
    public const KEY_ELASTICSEARCH_PASSWORD = 'elasticsearch_password';
    public const KEY_ELASTICSEARCH_API_KEY = 'elasticsearch_api_key';
    public const KEY_ELASTICSEARCH_VERIFY_TLS = 'elasticsearch_verify_tls';
    public const KEY_ALLOW_PRIVATE_NETWORKS = 'allow_private_networks';
    public const KEY_ALLOWED_IMAGE_MIME_TYPES = 'allowed_image_mime_types';
    public const KEY_DISABLED_IMAGE_MIME_TYPES = 'disabled_image_mime_types';

    public const ELASTICSEARCH_SOURCE_INHERITED = 'inherited';
    public const ELASTICSEARCH_SOURCE_CUSTOM = 'custom';
    public const DEFAULT_MAX_PARALLEL_EMBED_REQUESTS = 4;
    public const DEFAULT_IMAGE_BATCH_SIZE = 8;
    public const DEFAULT_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN = 8;
    public const DEFAULT_IMAGE_BATCH_REQUEST_TIMEOUT = 120;
    public const DEFAULT_INDEX_ALIAS = 'nc_media_embeddings_current';
    public const MEDIALAB_API_BASE_PATH = '/api/external/v1';

    /**
     * Formats the connector can reliably show in Nextcloud search results
     * without depending on optional preview providers. Admins may override this.
     *
     * @var list<string>
     */
    public const DEFAULT_ALLOWED_IMAGE_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Formats that are explicitly rejected even if a preview provider exists.
     *
     * @var list<string>
     */
    public const DEFAULT_DISABLED_IMAGE_MIME_TYPES = [
        'image/heic',
        'image/heif',
        'image/vnd.adobe.photoshop',
        'image/x-adobe-dng',
        'image/x-canon-cr2',
        'image/x-canon-cr3',
        'image/x-dcraw',
        'image/x-epson-erf',
        'image/x-fuji-raf',
        'image/x-kodak-dcr',
        'image/x-kodak-k25',
        'image/x-minolta-mrw',
        'image/x-nikon-nef',
        'image/x-olympus-orf',
        'image/x-panasonic-raw',
        'image/x-panasonic-rw2',
        'image/x-pentax-pef',
        'image/x-photoshop',
        'image/x-sony-arw',
        'image/x-sony-sr2',
        'image/x-sony-srf',
    ];

    public function __construct(private IAppConfig $config)
    {
    }

    public function getMediaLabOriginUrl(): string
    {
        return trim($this->getString(self::KEY_MEDIALAB_ORIGIN_URL));
    }

    public function setMediaLabOriginUrl(string $originUrl): void
    {
        $this->setString(self::KEY_MEDIALAB_ORIGIN_URL, rtrim(trim($originUrl), '/'));
    }

    public function getMediaLabApiBaseUrl(): string
    {
        return $this->buildMediaLabApiBaseUrl($this->getMediaLabOriginUrl());
    }

    public function buildMediaLabApiBaseUrl(string $originUrl): string
    {
        $originUrl = rtrim(trim($originUrl), '/');
        if ($originUrl === '') {
            return '';
        }

        if (str_ends_with($originUrl, self::MEDIALAB_API_BASE_PATH)) {
            return $originUrl;
        }

        return $originUrl . self::MEDIALAB_API_BASE_PATH;
    }

    public function getMediaLabToken(): string
    {
        return trim($this->getString(self::KEY_MEDIALAB_TOKEN, '', true));
    }

    public function setMediaLabToken(string $token): void
    {
        $this->setString(self::KEY_MEDIALAB_TOKEN, trim($token), true, true);
    }

    public function hasMediaLabConnectionSettings(): bool
    {
        return $this->getMediaLabApiBaseUrl() !== '' && $this->getMediaLabToken() !== '';
    }

    public function getMaxParallelEmbedRequests(): int
    {
        return $this->config->getValueInt(
            Application::APP_ID,
            self::KEY_MAX_PARALLEL_EMBED_REQUESTS,
            self::DEFAULT_MAX_PARALLEL_EMBED_REQUESTS,
        );
    }

    public function setMaxParallelEmbedRequests(int $maxParallel): void
    {
        $this->config->setValueInt(Application::APP_ID, self::KEY_MAX_PARALLEL_EMBED_REQUESTS, $maxParallel);
    }

    public function getImageBatchSize(): int
    {
        return $this->config->getValueInt(
            Application::APP_ID,
            self::KEY_IMAGE_BATCH_SIZE,
            self::DEFAULT_IMAGE_BATCH_SIZE,
        );
    }

    public function setImageBatchSize(int $batchSize): void
    {
        $this->config->setValueInt(Application::APP_ID, self::KEY_IMAGE_BATCH_SIZE, $batchSize);
    }

    public function getImageBatchMaxParallelRequestsPerToken(): int
    {
        return $this->config->getValueInt(
            Application::APP_ID,
            self::KEY_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN,
            self::DEFAULT_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN,
        );
    }

    public function setImageBatchMaxParallelRequestsPerToken(int $maxParallel): void
    {
        $this->config->setValueInt(Application::APP_ID, self::KEY_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN, $maxParallel);
    }

    public function getImageBatchRequestTimeout(): int
    {
        return $this->config->getValueInt(
            Application::APP_ID,
            self::KEY_IMAGE_BATCH_REQUEST_TIMEOUT,
            self::DEFAULT_IMAGE_BATCH_REQUEST_TIMEOUT,
        );
    }

    public function setImageBatchRequestTimeout(int $timeoutSeconds): void
    {
        $this->config->setValueInt(Application::APP_ID, self::KEY_IMAGE_BATCH_REQUEST_TIMEOUT, $timeoutSeconds);
    }

    public function getIndexAlias(): string
    {
        return $this->getString(self::KEY_INDEX_ALIAS, self::DEFAULT_INDEX_ALIAS);
    }

    public function setIndexAlias(string $alias): void
    {
        $this->setString(self::KEY_INDEX_ALIAS, trim($alias));
    }

    public function isIndexingEnabled(): bool
    {
        return $this->config->getValueBool(Application::APP_ID, self::KEY_INDEXING_ENABLED, false);
    }

    public function setIndexingEnabled(bool $enabled): void
    {
        $this->config->setValueBool(Application::APP_ID, self::KEY_INDEXING_ENABLED, $enabled);
    }

    public function getElasticsearchConfigSource(): string
    {
        return self::ELASTICSEARCH_SOURCE_CUSTOM;
    }

    public function setElasticsearchConfigSource(string $source): void
    {
        $this->setString(self::KEY_ELASTICSEARCH_CONFIG_SOURCE, $source);
    }

    public function getElasticsearchUrl(): string
    {
        return rtrim(trim($this->getString(self::KEY_ELASTICSEARCH_URL)), '/');
    }

    public function setElasticsearchUrl(string $url): void
    {
        $this->setString(self::KEY_ELASTICSEARCH_URL, rtrim(trim($url), '/'));
    }

    public function getElasticsearchUsername(): string
    {
        return $this->getString(self::KEY_ELASTICSEARCH_USERNAME, '', true);
    }

    public function setElasticsearchUsername(string $username): void
    {
        $this->setString(self::KEY_ELASTICSEARCH_USERNAME, trim($username), true, true);
    }

    public function getElasticsearchPassword(): string
    {
        return $this->getString(self::KEY_ELASTICSEARCH_PASSWORD, '', true);
    }

    public function setElasticsearchPassword(string $password): void
    {
        $this->setString(self::KEY_ELASTICSEARCH_PASSWORD, $password, true, true);
    }

    public function getElasticsearchApiKey(): string
    {
        return $this->getString(self::KEY_ELASTICSEARCH_API_KEY, '', true);
    }

    public function setElasticsearchApiKey(string $apiKey): void
    {
        $this->setString(self::KEY_ELASTICSEARCH_API_KEY, trim($apiKey), true, true);
    }

    public function shouldVerifyElasticsearchTls(): bool
    {
        return $this->config->getValueBool(Application::APP_ID, self::KEY_ELASTICSEARCH_VERIFY_TLS, true);
    }

    public function setVerifyElasticsearchTls(bool $verify): void
    {
        $this->config->setValueBool(Application::APP_ID, self::KEY_ELASTICSEARCH_VERIFY_TLS, $verify);
    }

    public function allowsPrivateNetworks(): bool
    {
        return $this->config->getValueBool(Application::APP_ID, self::KEY_ALLOW_PRIVATE_NETWORKS, false);
    }

    public function setAllowPrivateNetworks(bool $allow): void
    {
        $this->config->setValueBool(Application::APP_ID, self::KEY_ALLOW_PRIVATE_NETWORKS, $allow);
    }

    /**
     * @return list<string>
     */
    public function getAllowedImageMimeTypes(): array
    {
        $stored = self::splitMimeList($this->getString(self::KEY_ALLOWED_IMAGE_MIME_TYPES));
        return $stored !== [] ? $stored : self::DEFAULT_ALLOWED_IMAGE_MIME_TYPES;
    }

    /**
     * @param list<string> $mimeTypes
     */
    public function setAllowedImageMimeTypes(array $mimeTypes): void
    {
        $this->setString(self::KEY_ALLOWED_IMAGE_MIME_TYPES, implode(',', self::normalizeMimeList($mimeTypes)));
    }

    /**
     * @return list<string>
     */
    public function getDisabledImageMimeTypes(): array
    {
        return self::splitMimeList($this->getString(self::KEY_DISABLED_IMAGE_MIME_TYPES, implode(',', self::DEFAULT_DISABLED_IMAGE_MIME_TYPES)));
    }

    /**
     * @param list<string> $mimeTypes
     */
    public function setDisabledImageMimeTypes(array $mimeTypes): void
    {
        $this->setString(self::KEY_DISABLED_IMAGE_MIME_TYPES, implode(',', self::normalizeMimeList($mimeTypes)));
    }

    /**
     * Split a comma- or whitespace-separated list into normalised MIME types.
     *
     * @return list<string>
     */
    public static function splitMimeList(string $value): array
    {
        $parts = preg_split('/[\s,]+/', strtolower($value)) ?: [];
        return self::normalizeMimeList($parts);
    }

    /**
     * @param list<string> $mimeTypes
     * @return list<string>
     */
    public static function normalizeMimeList(array $mimeTypes): array
    {
        $clean = [];
        foreach ($mimeTypes as $mimeType) {
            $mimeType = strtolower(trim((string)$mimeType));
            if ($mimeType !== '' && !in_array($mimeType, $clean, true)) {
                $clean[] = $mimeType;
            }
        }
        return $clean;
    }

    /**
     * @param list<string> $mimeTypes
     * @return list<array{field:string, message:string}>
     */
    public function validateImageMimeTypes(array $mimeTypes, string $field): array
    {
        foreach ($mimeTypes as $mimeType) {
            if (!preg_match('#^image/[a-z0-9][a-z0-9.+-]*$#', $mimeType)) {
                return [['field' => $field, 'message' => 'Each image format must be an "image/…" MIME type.']];
            }
        }
        return [];
    }

    /**
     * @return list<array{field:string, message:string}>
     */
    public function validate(
        string $originUrl,
        int $maxParallel,
        string $indexAlias,
        string $elasticsearchSource,
        string $elasticsearchUrl,
        int $imageBatchSize = self::DEFAULT_IMAGE_BATCH_SIZE,
        int $imageBatchMaxParallelRequests = self::DEFAULT_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN,
        int $imageBatchRequestTimeout = self::DEFAULT_IMAGE_BATCH_REQUEST_TIMEOUT,
    ): array {
        $errors = [];
        $errors = array_merge($errors, $this->validateHttpUrl($originUrl, self::KEY_MEDIALAB_ORIGIN_URL, true));

        if ($maxParallel < 1 || $maxParallel > 32) {
            $errors[] = [
                'field' => self::KEY_MAX_PARALLEL_EMBED_REQUESTS,
                'message' => 'Parallel embedding requests must be between 1 and 32.',
            ];
        }

        if ($imageBatchSize < 1 || $imageBatchSize > 64) {
            $errors[] = [
                'field' => self::KEY_IMAGE_BATCH_SIZE,
                'message' => 'Image batch size must be between 1 and 64.',
            ];
        }
        if ($imageBatchMaxParallelRequests < 1 || $imageBatchMaxParallelRequests > 32) {
            $errors[] = [
                'field' => self::KEY_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN,
                'message' => 'Parallel image batch requests must be between 1 and 32.',
            ];
        }
        if ($imageBatchRequestTimeout < 10 || $imageBatchRequestTimeout > 600) {
            $errors[] = [
                'field' => self::KEY_IMAGE_BATCH_REQUEST_TIMEOUT,
                'message' => 'Image batch request timeout must be between 10 and 600 seconds.',
            ];
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,254}$/', $indexAlias)) {
            $errors[] = [
                'field' => self::KEY_INDEX_ALIAS,
                'message' => 'Index alias must contain only lowercase letters, numbers, underscore, dot, and dash.',
            ];
        }

        if (!in_array($elasticsearchSource, [
            self::ELASTICSEARCH_SOURCE_CUSTOM,
        ], true)) {
            $errors[] = [
                'field' => self::KEY_ELASTICSEARCH_CONFIG_SOURCE,
                'message' => 'Unsupported Elasticsearch configuration source.',
            ];
        }

        if ($elasticsearchSource === self::ELASTICSEARCH_SOURCE_CUSTOM) {
            $errors = array_merge(
                $errors,
                $this->validateHttpUrl($elasticsearchUrl, self::KEY_ELASTICSEARCH_URL, false),
            );
        }

        return $errors;
    }

    /**
     * @return list<array{field:string, message:string}>
     */
    public function validateHttpUrl(string $url, string $field, bool $allowApiPath): array
    {
        $url = trim($url);
        if ($url === '') {
            return [['field' => $field, 'message' => 'An absolute HTTP(S) URL is required.']];
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return [['field' => $field, 'message' => 'An absolute HTTP(S) URL is required.']];
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return [['field' => $field, 'message' => 'URL must use HTTP or HTTPS.']];
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            return [['field' => $field, 'message' => 'URL must not contain credentials, query parameters, or fragments.']];
        }

        if (!$allowApiPath && !in_array(rtrim($parts['path'] ?? '', '/'), ['', '/'], true)) {
            return [['field' => $field, 'message' => 'Elasticsearch URL must contain only the server origin.']];
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if ($allowApiPath && $path === '/v1') {
            return [['field' => $field, 'message' => 'Use the server origin or /api/external/v1; /v1 alone is not supported.']];
        }
        if ($allowApiPath && !in_array($path, ['', '/', self::MEDIALAB_API_BASE_PATH], true)) {
            return [[
                'field' => $field,
                'message' => 'Media Embedding Service URL must be the server origin or end with /api/external/v1.',
            ]];
        }

        return [];
    }

    private function getString(string $key, string $default = '', bool $lazy = false): string
    {
        if (
            $lazy
            && !$this->config->hasKey(Application::APP_ID, $key, true)
            && $this->config->hasKey(Application::APP_ID, $key, false)
        ) {
            return $this->config->getValueString(Application::APP_ID, $key, $default, false);
        }
        return $this->config->getValueString(Application::APP_ID, $key, $default, $lazy);
    }

    private function setString(string $key, string $value, bool $lazy = false, bool $sensitive = false): void
    {
        if ($this->config->hasKey(Application::APP_ID, $key, null)) {
            $this->config->updateLazy(Application::APP_ID, $key, $lazy);
            $this->config->updateSensitive(Application::APP_ID, $key, $sensitive);
        }
        $this->config->setValueString(Application::APP_ID, $key, $value, $lazy, $sensitive);
    }
}
