<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Controller;

use OCA\MediaEmbeddingConnector\AppInfo\Application;
use OCA\MediaEmbeddingConnector\BackgroundJob\DiscoverBackfillUsersJob;
use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessImageEmbeddingBatchJob;
use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessIndexJob;
use OCA\MediaEmbeddingConnector\Db\AuditRepository;
use OCA\MediaEmbeddingConnector\Db\IndexedFileRepository;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ElasticsearchClient;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingService;
use OCA\MediaEmbeddingConnector\Service\IndexLifecycleService;
use OCA\MediaEmbeddingConnector\Service\IndexMappingFactory;
use OCA\MediaEmbeddingConnector\Service\MediaLabConnectionTestService;
use OCA\MediaEmbeddingConnector\Service\MediaLabContractService;
use OCA\MediaEmbeddingConnector\Service\SearchPluginDetector;
use OCA\MediaEmbeddingConnector\Service\TextEmbeddingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class AdminController extends Controller
{
    public function __construct(
        IRequest $request,
        private AppConfig $config,
        private MediaLabContractService $contractService,
        private ImageEmbeddingService $imageEmbeddingService,
        private IndexMappingFactory $indexMappingFactory,
        private SearchPluginDetector $searchPluginDetector,
        private SkipMarkerRepository $skipMarkerRepository,
        private TextEmbeddingService $textEmbeddingService,
        private MediaLabConnectionTestService $mediaLabTest,
        private ElasticsearchClient $elasticsearch,
        private IndexLifecycleService $indexLifecycle,
        private IndexJobRepository $jobs,
        private IndexedFileRepository $indexedFiles,
        private AuditRepository $audit,
        private IJobList $jobList,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function save(): DataResponse
    {
        $originUrl = trim((string)$this->request->getParam('medialab_origin_url', ''));
        $token = trim((string)$this->request->getParam('medialab_token', ''));
        $maxParallel = (int)$this->request->getParam(
            'max_parallel_embed_requests',
            AppConfig::DEFAULT_MAX_PARALLEL_EMBED_REQUESTS,
        );
        $imageBatchSize = (int)$this->request->getParam(
            'image_batch_size',
            AppConfig::DEFAULT_IMAGE_BATCH_SIZE,
        );
        $imageBatchMaxParallel = (int)$this->request->getParam(
            'image_batch_max_parallel_requests_per_token',
            AppConfig::DEFAULT_IMAGE_BATCH_MAX_PARALLEL_REQUESTS_PER_TOKEN,
        );
        $imageBatchTimeout = (int)$this->request->getParam(
            'image_batch_request_timeout',
            AppConfig::DEFAULT_IMAGE_BATCH_REQUEST_TIMEOUT,
        );
        $indexAlias = trim((string)$this->request->getParam('index_alias', AppConfig::DEFAULT_INDEX_ALIAS));
        $source = (string)$this->request->getParam(
            'elasticsearch_config_source',
            AppConfig::ELASTICSEARCH_SOURCE_CUSTOM,
        );
        $elasticsearchUrl = trim((string)$this->request->getParam('elasticsearch_url', ''));
        $allowedImageMimeTypes = AppConfig::splitMimeList((string)$this->request->getParam('allowed_image_mime_types', ''));
        $disabledImageMimeTypes = AppConfig::splitMimeList((string)$this->request->getParam('disabled_image_mime_types', ''));
        $errors = $this->config->validate(
            $originUrl,
            $maxParallel,
            $indexAlias,
            $source,
            $elasticsearchUrl,
            $imageBatchSize,
            $imageBatchMaxParallel,
            $imageBatchTimeout,
        );
        $errors = array_merge(
            $errors,
            $this->config->validateImageMimeTypes($allowedImageMimeTypes, AppConfig::KEY_ALLOWED_IMAGE_MIME_TYPES),
            $this->config->validateImageMimeTypes($disabledImageMimeTypes, AppConfig::KEY_DISABLED_IMAGE_MIME_TYPES),
        );
        if ($errors !== []) {
            return new DataResponse(['success' => false, 'errors' => $errors], 400);
        }

        $this->config->setMediaLabOriginUrl($originUrl);
        if ($token !== '') {
            $this->config->setMediaLabToken($token);
        }
        $this->config->setMaxParallelEmbedRequests($maxParallel);
        $this->config->setImageBatchSize($imageBatchSize);
        $this->config->setImageBatchMaxParallelRequestsPerToken($imageBatchMaxParallel);
        $this->config->setImageBatchRequestTimeout($imageBatchTimeout);
        $this->config->setIndexAlias($indexAlias);
        $this->config->setElasticsearchConfigSource($source);
        $this->config->setElasticsearchUrl($elasticsearchUrl);
        $this->config->setVerifyElasticsearchTls(
            $this->request->getParam('elasticsearch_verify_tls', '0') === '1',
        );
        $this->config->setAllowPrivateNetworks(
            $this->request->getParam('allow_private_networks', '0') === '1',
        );
        $this->config->setAllowedImageMimeTypes($allowedImageMimeTypes);
        $this->config->setDisabledImageMimeTypes($disabledImageMimeTypes);

        $username = trim((string)$this->request->getParam('elasticsearch_username', ''));
        $password = (string)$this->request->getParam('elasticsearch_password', '');
        $apiKey = trim((string)$this->request->getParam('elasticsearch_api_key', ''));
        if ($username !== '') {
            $this->config->setElasticsearchUsername($username);
        }
        if ($password !== '') {
            $this->config->setElasticsearchPassword($password);
        }
        if ($apiKey !== '') {
            $this->config->setElasticsearchApiKey($apiKey);
        }

        $this->recordAudit('settings_saved', 'app', Application::APP_ID, 'success');
        return new DataResponse([
            'success' => true,
            'api_base_url' => $this->config->getMediaLabApiBaseUrl(),
            'search_plugins' => $this->searchPluginDetector->detect(),
        ]);
    }

    public function probe(): DataResponse
    {
        try {
            $contract = $this->config->hasMediaLabConnectionSettings()
                ? $this->contractService->getDefaultModelContract()
                : null;
            return new DataResponse([
                'success' => true,
                'api_base_url' => $this->config->getMediaLabApiBaseUrl(),
                'model_contract' => $contract,
                'health' => $contract !== null ? $this->contractService->getHealth() : null,
                'index' => $contract !== null ? $this->indexMappingFactory->buildFromContract($contract) : null,
                'search_plugins' => $this->searchPluginDetector->detect(),
                'skip_markers' => $this->skipMarkerRepository->getStats(),
            ]);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'medialab_probe_failed');
        }
    }

    public function testMediaLab(): DataResponse
    {
        $originUrl = trim((string)$this->request->getParam('medialab_origin_url', ''));
        $token = trim((string)$this->request->getParam('medialab_token', ''));
        $iterations = (int)$this->request->getParam('iterations', 5);
        $includeText = $this->request->getParam('include_text_embedding', '0') === '1';
        $allowPrivateNetworks = $this->request->getParam('allow_private_networks', '0') === '1';
        $errors = $this->config->validateHttpUrl(
            $originUrl !== '' ? $originUrl : $this->config->getMediaLabOriginUrl(),
            AppConfig::KEY_MEDIALAB_ORIGIN_URL,
            true,
        );
        if ($errors !== []) {
            return new DataResponse(['success' => false, 'errors' => $errors], 400);
        }

        try {
            $result = $this->mediaLabTest->run(
                $originUrl,
                $token,
                $iterations,
                $includeText,
                $allowPrivateNetworks,
            );
            $this->recordAudit('medialab_test', 'service', 'medialab', $result['success'] ? 'success' : 'failed');
            return new DataResponse($result, $result['success'] ? 200 : 502);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'medialab_test_failed');
        }
    }

    public function testElasticsearch(): DataResponse
    {
        $overrides = $this->elasticsearchOverrides();
        if (($overrides['source'] ?? '') === AppConfig::ELASTICSEARCH_SOURCE_CUSTOM) {
            $errors = $this->config->validateHttpUrl(
                (string)($overrides['url'] ?: $this->config->getElasticsearchUrl()),
                AppConfig::KEY_ELASTICSEARCH_URL,
                false,
            );
            if ($errors !== []) {
                return new DataResponse(['success' => false, 'errors' => $errors], 400);
            }
        }

        try {
            $result = $this->elasticsearch->testConnection($overrides);
            $this->recordAudit('elasticsearch_test', 'service', 'elasticsearch', 'success');
            return new DataResponse($result);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'elasticsearch_test_failed');
        }
    }

    public function status(): DataResponse
    {
        try {
            $indices = $this->elasticsearch->listManagedIndices();
        } catch (\Throwable) {
            $indices = [];
        }

        return new DataResponse([
            'success' => true,
            'indexing_enabled' => $this->config->isIndexingEnabled(),
            'lifecycle' => $this->indexLifecycle->getStatus(),
            'jobs' => $this->jobs->getStats(),
            'indexed_files' => $this->indexedFiles->count(),
            'skip_markers' => $this->skipMarkerRepository->getStats(),
            'indices' => $indices,
            'audit' => $this->audit->latest(30),
        ]);
    }

    public function prepareIndex(): DataResponse
    {
        try {
            $result = $this->indexLifecycle->prepare(
                $this->request->getParam('confirm_model_change', '0') === '1',
            );
            $this->recordAudit('index_prepared', 'index', (string)$result['write_index'], 'success');
            return new DataResponse(['success' => true] + $result);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'index_prepare_failed');
        }
    }

    public function activateIndex(): DataResponse
    {
        $stats = $this->jobs->getStats();
        if (
            $stats[IndexJobRepository::STATUS_QUEUED] > 0
            || $stats[IndexJobRepository::STATUS_RUNNING] > 0
            || $stats[IndexJobRepository::STATUS_FAILED] > 0
        ) {
            return new DataResponse([
                'success' => false,
                'errors' => [[
                    'code' => 'index_jobs_incomplete',
                    'message' => 'Index jobs are still pending or failed.',
                ]],
            ], 409);
        }

        try {
            $result = $this->indexLifecycle->activateWriteIndex();
            $this->recordAudit('index_activated', 'index', (string)$result['active_index'], 'success');
            return new DataResponse(['success' => true] + $result);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'index_activate_failed');
        }
    }

    public function setIndexingEnabled(): DataResponse
    {
        $enabled = $this->request->getParam('enabled', '0') === '1';
        try {
            if ($enabled) {
                $this->indexLifecycle->prepare(false);
            }
            $this->config->setIndexingEnabled($enabled);
            if ($enabled) {
                foreach ($this->jobs->getQueuedIds(10_000, IndexJobRepository::ACTION_DELETE) as $jobId) {
                    $this->jobList->add(ProcessIndexJob::class, ['job_id' => $jobId]);
                }
                $this->scheduleImageBatchWorkerIfQueued();
            }
            $this->recordAudit('indexing_toggled', 'app', Application::APP_ID, 'success', ['enabled' => $enabled]);
            return new DataResponse(['success' => true, 'indexing_enabled' => $enabled]);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'indexing_toggle_failed');
        }
    }

    public function startBackfill(): DataResponse
    {
        if (!$this->config->isIndexingEnabled()) {
            return new DataResponse([
                'success' => false,
                'errors' => [['code' => 'indexing_disabled', 'message' => 'Enable indexing first.']],
            ], 409);
        }

        $this->indexLifecycle->setBackfillPaused(false);
        $this->jobList->add(DiscoverBackfillUsersJob::class, ['offset' => 0]);
        $this->recordAudit('backfill_started', 'backfill', null, 'success');
        return new DataResponse(['success' => true]);
    }

    public function setBackfillPaused(): DataResponse
    {
        $paused = $this->request->getParam('paused', '1') === '1';
        $this->indexLifecycle->setBackfillPaused($paused);
        if (!$paused) {
            $this->jobList->add(DiscoverBackfillUsersJob::class, ['offset' => 0]);
        }
        $this->recordAudit($paused ? 'backfill_paused' : 'backfill_resumed', 'backfill', null, 'success');
        return new DataResponse(['success' => true, 'paused' => $paused]);
    }

    public function retryJobs(): DataResponse
    {
        $errorCode = trim((string)$this->request->getParam('error_code', ''));
        $ids = $this->jobs->retryFailed($errorCode !== '' ? $errorCode : null);
        $hasImageIndexJobs = false;
        foreach ($ids as $id) {
            $job = $this->jobs->findById($id);
            if (($job['action'] ?? IndexJobRepository::ACTION_INDEX) === IndexJobRepository::ACTION_DELETE) {
                $this->jobList->add(ProcessIndexJob::class, ['job_id' => $id]);
                continue;
            }
            $hasImageIndexJobs = true;
        }
        if ($hasImageIndexJobs) {
            $this->jobList->add(ProcessImageEmbeddingBatchJob::class, [
                'batch_size' => $this->config->getImageBatchSize(),
            ]);
        }
        $this->recordAudit('jobs_retried', 'job', $errorCode ?: null, 'success', ['count' => count($ids)]);
        return new DataResponse(['success' => true, 'retried' => count($ids)]);
    }

    private function scheduleImageBatchWorkerIfQueued(): void
    {
        if ($this->jobs->getQueuedIds(1, IndexJobRepository::ACTION_INDEX) === []) {
            return;
        }

        $this->jobList->add(ProcessImageEmbeddingBatchJob::class, [
            'batch_size' => $this->config->getImageBatchSize(),
        ]);
    }

    public function deleteIndex(): DataResponse
    {
        $indexName = trim((string)$this->request->getParam('index_name', ''));
        $lifecycle = $this->indexLifecycle->getStatus();
        if ($indexName === '' || in_array($indexName, [
            $lifecycle['write_index'] ?? null,
            $lifecycle['search_index'] ?? null,
        ], true)) {
            return new DataResponse([
                'success' => false,
                'errors' => [['code' => 'index_in_use', 'message' => 'The active write or search index cannot be deleted.']],
            ], 409);
        }

        try {
            $this->elasticsearch->deleteManagedIndex($indexName);
            $this->recordAudit('index_deleted', 'index', $indexName, 'success');
            return new DataResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'index_delete_failed');
        }
    }

    public function probeImage(): DataResponse
    {
        $uploadedFile = $this->request->getUploadedFile('image');
        if (!is_array($uploadedFile) || ($uploadedFile['tmp_name'] ?? '') === '') {
            return new DataResponse([
                'success' => false,
                'errors' => [['code' => 'missing_image', 'message' => 'Upload one image.']],
            ], 400);
        }

        try {
            return new DataResponse([
                'success' => true,
                'image_probe' => $this->imageEmbeddingService->embedUploadedFileForProbe($uploadedFile),
            ]);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'image_probe_failed');
        }
    }

    public function probeText(): DataResponse
    {
        try {
            return new DataResponse([
                'success' => true,
                'text_probe' => $this->textEmbeddingService->embedTextForProbe(
                    (string)$this->request->getParam('text', ''),
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->externalError($e, 'text_probe_failed');
        }
    }

    public function resetSkipMarkers(): DataResponse
    {
        $fileId = trim((string)$this->request->getParam('nextcloud_file_id', ''));
        $reason = trim((string)$this->request->getParam('reason', ''));
        $removed = $fileId !== ''
            ? $this->skipMarkerRepository->resetFile($fileId)
            : ($reason !== ''
                ? $this->skipMarkerRepository->resetByReason($reason)
                : $this->skipMarkerRepository->resetAll());
        $this->recordAudit('skip_markers_reset', 'skip_marker', $fileId ?: $reason ?: null, 'success', ['removed' => $removed]);

        return new DataResponse([
            'success' => true,
            'removed' => $removed,
            'skip_markers' => $this->skipMarkerRepository->getStats(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function elasticsearchOverrides(): array
    {
        return [
            'source' => (string)$this->request->getParam(
                'elasticsearch_config_source',
                $this->config->getElasticsearchConfigSource(),
            ),
            'url' => trim((string)$this->request->getParam('elasticsearch_url', '')),
            'username' => trim((string)$this->request->getParam('elasticsearch_username', '')),
            'password' => (string)$this->request->getParam('elasticsearch_password', ''),
            'api_key' => trim((string)$this->request->getParam('elasticsearch_api_key', '')),
            'verify_tls' => $this->request->getParam('elasticsearch_verify_tls', '0') === '1',
            'allow_private_networks' => $this->request->getParam('allow_private_networks', '0') === '1',
        ];
    }

    private function externalError(\Throwable $e, string $fallbackCode): DataResponse
    {
        $code = $e instanceof ExternalServiceException ? $e->getPublicCode() : $fallbackCode;
        $context = [
            'app' => Application::APP_ID,
            'error_code' => $code,
            'exception_class' => $e::class,
        ];
        if (!$e instanceof ExternalServiceException) {
            $context['exception'] = $e;
        }
        $this->logger->warning('Media Embedding Service admin action failed', $context);

        $status = match ($code) {
            'model_change_confirmation_required',
            'index_not_prepared' => 409,
            'medialab_not_configured',
            'elasticsearch_not_configured' => 400,
            default => 502,
        };
        return new DataResponse([
            'success' => false,
            'errors' => [['code' => $code, 'message' => 'The requested operation failed. Check the Nextcloud log for details.']],
        ], $status);
    }

    /**
     * @param array<string, scalar|null> $details
     */
    private function recordAudit(
        string $action,
        string $targetType,
        ?string $targetId,
        string $result,
        array $details = [],
    ): void {
        $this->audit->record(
            $this->userSession->getUser()?->getUID(),
            $action,
            $targetType,
            $targetId,
            $result,
            $details,
        );
    }
}
