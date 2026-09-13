<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\IndexedFileRepository;
use OCA\MediaEmbeddingConnector\Db\SkipMarkerRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;

class FileIndexingService
{
    public function __construct(
        private IRootFolder $rootFolder,
        private IMimeTypeDetector $mimeTypeDetector,
        private MediaLabContractService $contractService,
        private ImageEligibilityService $eligibilityService,
        private MediaLabClient $mediaLab,
        private VectorValidator $vectorValidator,
        private ElasticsearchClient $elasticsearch,
        private IndexLifecycleService $indexLifecycle,
        private AppConfig $config,
        private AppAccessPolicy $accessPolicy,
        private IndexedFileRepository $indexedFiles,
        private SkipMarkerRepository $skipMarkers,
    ) {
    }

    /**
     * @param array<string, mixed> $job
     */
    public function process(array $job): string
    {
        $contract = $this->contractService->getDefaultModelContract();
        $prepared = $this->prepareImageForEmbedding($job, $contract);
        if (($prepared['status'] ?? null) !== 'ready') {
            return (string)$prepared['status'];
        }

        try {
            $response = $this->mediaLab->embedImageFile((string)$prepared['tmp_path'], (string)$prepared['mime_type']);
            return $this->indexPreparedImage($prepared, $response);
        } catch (ExternalServiceException $e) {
            $skipReason = $e->getSkipReason();
            if ($skipReason !== null) {
                $node = $prepared['node'] ?? null;
                if ($node instanceof File) {
                    $this->skipMarkers->put((string)$prepared['file_id'], $node->getEtag(), $skipReason, $contract);
                }
                return 'skipped';
            }
            throw $e;
        } finally {
            $this->cleanupPreparedImage($prepared);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function prepareImageForEmbedding(array $job, array $contract): array
    {
        $fileId = (string)$job['file_id'];
        if (($job['action'] ?? '') === 'delete') {
            $this->delete($fileId);
            return ['status' => 'indexed', 'file_id' => $fileId];
        }

        $ownerUid = trim((string)($job['owner_uid'] ?? ''));
        if ($ownerUid === '') {
            throw new ExternalServiceException('File owner is unavailable.', 'file_owner_unavailable');
        }
        if (!$this->accessPolicy->isUserIdAllowed($ownerUid)) {
            $this->delete($fileId);
            return ['status' => 'skipped', 'file_id' => $fileId, 'reason' => 'access_denied'];
        }

        try {
            $nodes = $this->rootFolder->getUserFolder($ownerUid)->getById((int)$fileId);
        } catch (\Throwable) {
            $this->delete($fileId);
            return ['status' => 'skipped', 'file_id' => $fileId, 'reason' => 'file_owner_unavailable'];
        }
        $node = null;
        foreach ($nodes as $candidate) {
            if ($candidate instanceof File && $candidate->isReadable()) {
                $node = $candidate;
                break;
            }
        }
        if (!$node instanceof File) {
            $this->delete($fileId);
            return ['status' => 'indexed', 'file_id' => $fileId];
        }

        $activeContract = $this->indexLifecycle->getStatus()['active_contract'];
        if (($activeContract['model_fingerprint'] ?? null) !== ($contract['model_fingerprint'] ?? null)) {
            $this->config->setIndexingEnabled(false);
            throw new ExternalServiceException(
                'Media Embedding Service model changed during indexing.',
                'model_change_confirmation_required',
            );
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'nc-medialab-');
        if ($tmpPath === false) {
            throw new ExternalServiceException('Temporary file could not be created.', 'temporary_file_failed', true);
        }

        $input = $node->fopen('rb');
        $output = fopen($tmpPath, 'wb');
        if (!is_resource($input) || !is_resource($output)) {
            @unlink($tmpPath);
            throw new ExternalServiceException('Image stream could not be opened.', 'image_not_readable', true);
        }
        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        $mimeType = ImageEligibilityService::normalizeMimeType($this->mimeTypeDetector->detectContent($tmpPath));
        $imageInfo = @getimagesize($tmpPath);
        $pixelCount = is_array($imageInfo) ? $imageInfo[0] * $imageInfo[1] : null;
        $sizeBytes = filesize($tmpPath);
        $eligibility = $this->eligibilityService->evaluate(
            $mimeType,
            is_int($sizeBytes) ? $sizeBytes : null,
            $pixelCount,
            $contract,
        );
        if ($eligibility['allowed'] !== true) {
            @unlink($tmpPath);
            $reason = (string)$eligibility['reason'];
            $this->skipMarkers->put($fileId, $node->getEtag(), $reason, $contract);
            return ['status' => 'skipped', 'file_id' => $fileId, 'reason' => $reason];
        }

        return [
            'status' => 'ready',
            'job' => $job,
            'file_id' => $fileId,
            'owner_uid' => $ownerUid,
            'node' => $node,
            'tmp_path' => $tmpPath,
            'mime_type' => $mimeType,
            'size_bytes' => is_int($sizeBytes) ? $sizeBytes : 0,
            'pixel_count' => $pixelCount ?? 0,
            'contract' => $contract,
        ];
    }

    /**
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $response
     */
    public function indexPreparedImage(array $prepared, array $response): string
    {
        $contract = is_array($prepared['contract'] ?? null) ? $prepared['contract'] : [];
        $node = $prepared['node'] ?? null;
        if (!$node instanceof File) {
            throw new ExternalServiceException('Prepared image is no longer available.', 'image_not_readable', true);
        }

        $vector = $response['image_vector'] ?? null;
        $errors = $this->vectorValidator->validate(
            $vector,
            (int)$contract['embedding_dim'],
            (bool)$contract['normalized'],
        );
        if ($errors !== []) {
            throw new ExternalServiceException(
                'Media Embedding Service image vector failed validation.',
                'invalid_image_vector',
            );
        }

        $fileId = (string)$prepared['file_id'];
        $ownerUid = (string)$prepared['owner_uid'];
        $owner = $node->getOwner();
        $storageId = (string)$node->getMountPoint()->getStorageId();
        $indexName = $this->indexLifecycle->getWriteIndex();
        $document = [
            'nextcloud_file_id' => $fileId,
            'storage_id' => $storageId,
            'etag' => $node->getEtag(),
            'mime_type' => (string)$prepared['mime_type'],
            'size_bytes' => (int)$node->getSize(),
            'mtime' => gmdate('c', $node->getMTime()),
            'image_vector' => $vector,
            'model_id' => $response['model_id'] ?? $contract['model_id'],
            'model_name' => $response['model_name'] ?? $contract['model_name'],
            'model_version' => $response['model_version'] ?? $contract['model_version'],
            'model_fingerprint' => $response['model_fingerprint'] ?? $contract['model_fingerprint'],
            'embedding_dim' => $response['embedding_dim'] ?? $contract['embedding_dim'],
            'normalized' => $response['normalized'] ?? $contract['normalized'],
            'similarity' => $response['similarity'] ?? $contract['similarity'],
            'technical_metadata' => $response['technical_metadata'] ?? null,
            'indexed_at' => gmdate('c'),
            'embedding_request_id' => $response['request_id'] ?? null,
        ];
        $this->elasticsearch->upsertDocument($indexName, $fileId, $document);
        $this->indexedFiles->upsert([
            'file_id' => $fileId,
            'storage_id' => $storageId,
            'owner_uid' => $owner?->getUID() ?? $ownerUid,
            'etag' => $node->getEtag(),
            'mime_type' => (string)$prepared['mime_type'],
            'size_bytes' => (int)$node->getSize(),
            'mtime' => $node->getMTime(),
            'index_name' => $indexName,
            'model_id' => $document['model_id'],
            'model_version' => $document['model_version'],
            'embedding_dim' => $document['embedding_dim'],
            'contract_version' => $contract['contract_version'] ?? '',
            'model_fingerprint' => $document['model_fingerprint'],
        ]);
        $this->skipMarkers->resetFile($fileId);
        return 'indexed';
    }

    /**
     * @param array<string, mixed> $prepared
     */
    public function skipPreparedImage(array $prepared, string $reason): void
    {
        $node = $prepared['node'] ?? null;
        $contract = is_array($prepared['contract'] ?? null) ? $prepared['contract'] : [];
        if ($node instanceof File) {
            $this->skipMarkers->put((string)$prepared['file_id'], $node->getEtag(), $reason, $contract);
        }
    }

    /**
     * @param array<string, mixed> $prepared
     */
    public function cleanupPreparedImage(array $prepared): void
    {
        $tmpPath = (string)($prepared['tmp_path'] ?? '');
        if ($tmpPath !== '') {
            @unlink($tmpPath);
        }
    }

    private function delete(string $fileId): void
    {
        $record = $this->indexedFiles->find($fileId);
        if ($record !== null) {
            $this->elasticsearch->deleteDocument((string)$record['index_name'], $fileId);
        }
        $this->elasticsearch->deleteDocument($this->indexLifecycle->getSearchAlias(), $fileId);
        $this->indexedFiles->delete($fileId);
        $this->skipMarkers->resetFile($fileId);
    }
}
