<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Settings;

use OCA\MediaEmbeddingConnector\AppInfo\Application;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\SearchPluginDetector;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
    public function __construct(
        private AppConfig $config,
        private SearchPluginDetector $searchPluginDetector,
        private IURLGenerator $urlGenerator,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $this->initialState->provideInitialState('admin', [
            'medialab_origin_url' => $this->config->getMediaLabOriginUrl(),
            'medialab_token_configured' => $this->config->getMediaLabToken() !== '',
            'api_base_url' => $this->config->getMediaLabApiBaseUrl(),
            'index_alias' => $this->config->getIndexAlias(),
            'max_parallel_embed_requests' => $this->config->getMaxParallelEmbedRequests(),
            'image_batch_size' => $this->config->getImageBatchSize(),
            'image_batch_max_parallel_requests_per_token' => $this->config->getImageBatchMaxParallelRequestsPerToken(),
            'image_batch_request_timeout' => $this->config->getImageBatchRequestTimeout(),
            'allowed_image_mime_types' => implode(', ', $this->config->getAllowedImageMimeTypes()),
            'disabled_image_mime_types' => implode(', ', $this->config->getDisabledImageMimeTypes()),
            'indexing_enabled' => $this->config->isIndexingEnabled(),
            'elasticsearch_config_source' => $this->config->getElasticsearchConfigSource(),
            'elasticsearch_url' => $this->config->getElasticsearchUrl(),
            'elasticsearch_username_configured' => $this->config->getElasticsearchUsername() !== '',
            'elasticsearch_password_configured' => $this->config->getElasticsearchPassword() !== '',
            'elasticsearch_api_key_configured' => $this->config->getElasticsearchApiKey() !== '',
            'elasticsearch_verify_tls' => $this->config->shouldVerifyElasticsearchTls(),
            'allow_private_networks' => $this->config->allowsPrivateNetworks(),
            'search_plugins' => $this->searchPluginDetector->detect(),
            'save_url' => $this->route('save'),
            'probe_url' => $this->route('probe'),
            'test_medialab_url' => $this->route('testMediaLab'),
            'test_elasticsearch_url' => $this->route('testElasticsearch'),
            'status_url' => $this->route('status'),
            'prepare_index_url' => $this->route('prepareIndex'),
            'activate_index_url' => $this->route('activateIndex'),
            'indexing_url' => $this->route('setIndexingEnabled'),
            'start_backfill_url' => $this->route('startBackfill'),
            'pause_backfill_url' => $this->route('setBackfillPaused'),
            'retry_jobs_url' => $this->route('retryJobs'),
            'delete_index_url' => $this->route('deleteIndex'),
            'probe_image_url' => $this->route('probeImage'),
            'probe_text_url' => $this->route('probeText'),
            'reset_skip_markers_url' => $this->route('resetSkipMarkers'),
        ]);

        return new TemplateResponse(Application::APP_ID, 'settings/admin', [], '');
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function route(string $method): string
    {
        return $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.' . $method);
    }
}
