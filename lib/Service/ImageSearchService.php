<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;

class ImageSearchService
{
    public function __construct(
        private MediaLabContractService $contractService,
        private MediaLabClient $mediaLab,
        private VectorValidator $vectorValidator,
        private ElasticsearchClient $elasticsearch,
        private IndexLifecycleService $indexLifecycle,
        private IRootFolder $rootFolder,
        private IURLGenerator $urlGenerator,
        private ImageEligibilityService $eligibilityService,
        private ImageEmbeddingService $imageEmbeddingService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function searchText(string $userId, string $query, int $limit, int $offset): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Search query must not be empty.');
        }

        $contract = $this->contractService->getDefaultModelContract();
        $maxChars = (int)($contract['text_input']['max_chars'] ?? 0);
        if ($maxChars > 0 && mb_strlen($query) > $maxChars) {
            throw new \InvalidArgumentException('Search query is too long.');
        }

        $response = $this->mediaLab->embedText($query);
        $vector = $response['query_vector'] ?? null;
        $errors = $this->vectorValidator->validate(
            $vector,
            (int)$contract['embedding_dim'],
            (bool)$contract['normalized'],
        );
        if ($errors !== [] || !is_array($vector)) {
            throw new ExternalServiceException('Text vector failed validation.', 'invalid_text_vector');
        }

        $vector = $this->vectorValidator->normalizeValidated($vector);
        return $this->searchVector($userId, $vector, $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    public function searchSimilar(string $userId, string $fileId, int $limit, int $offset): array
    {
        $sourceNode = $this->requireVisibleNode($userId, $fileId);
        $vector = $this->elasticsearch->getDocumentVector(
            $this->indexLifecycle->getSearchAlias(),
            $fileId,
        );
        if ($vector === null) {
            throw new ExternalServiceException('Image is not indexed.', 'image_not_indexed');
        }

        $results = $this->searchVector($userId, $vector, $limit, $offset, $fileId);
        if ($offset === 0) {
            array_unshift($results['results'], $this->serializeResult($userId, $sourceNode, 1.0, true));
        }
        return $results;
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public function searchUploadedImage(
        string $userId,
        array $uploadedFile,
        int $limit,
        int $offset,
    ): array {
        $vector = $this->imageEmbeddingService->embedUploadedFile($uploadedFile);
        return $this->searchVector($userId, $vector, $limit, $offset);
    }

    /**
     * @param list<float|int> $vector
     * @return array<string, mixed>
     */
    private function searchVector(
        string $userId,
        array $vector,
        int $limit,
        int $offset,
        ?string $excludeFileId = null,
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(1000, $offset));
        $candidateLimit = min(500, max(($offset + $limit) * 5, 100));
        $candidates = $this->elasticsearch->search(
            $this->indexLifecycle->getSearchAlias(),
            $vector,
            $candidateLimit,
            $excludeFileId,
        );

        $visible = [];
        foreach ($candidates as $candidate) {
            $node = $this->findVisibleNode($userId, $candidate['file_id']);
            if (!$node instanceof File) {
                continue;
            }
            if (!$this->eligibilityService->isIndexingCandidate($node->getMimeType(), $node->getName())) {
                continue;
            }
            $visible[] = $this->serializeResult($userId, $node, $candidate['score']);
            if (count($visible) >= $offset + $limit + 1) {
                break;
            }
        }

        $page = array_slice($visible, $offset, $limit);
        return [
            'results' => $page,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => count($visible) > $offset + $limit,
            'next_offset' => count($visible) > $offset + $limit ? $offset + $limit : null,
        ];
    }

    private function requireVisibleNode(string $userId, string $fileId): File
    {
        $node = $this->findVisibleNode($userId, $fileId);
        if (!$node instanceof File) {
            throw new ExternalServiceException('File is not accessible.', 'file_not_accessible');
        }
        if (!$this->eligibilityService->isIndexingCandidate($node->getMimeType(), $node->getName())) {
            throw new ExternalServiceException('Image type is not supported.', 'unsupported_image_type');
        }
        return $node;
    }

    private function findVisibleNode(string $userId, string $fileId): ?File
    {
        try {
            $nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$fileId);
            foreach ($nodes as $node) {
                if ($node instanceof File && $node->isReadable()) {
                    return $node;
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResult(string $userId, File $node, float $score, bool $isReference = false): array
    {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $relativePath = $userFolder->getRelativePath($node->getPath());

        return [
            'file_id' => (string)$node->getId(),
            'name' => $node->getName(),
            'path' => $relativePath === null ? '' : $relativePath,
            'mime_type' => $node->getMimeType(),
            'mtime' => $node->getMTime(),
            'etag' => $node->getEtag(),
            'size' => $node->getSize(),
            'has_preview' => true,
            'score' => $score,
            'is_reference' => $isReference,
            'thumbnail_url' => $this->urlGenerator->linkToRouteAbsolute(
                'core.Preview.getPreviewByFileId',
                ['x' => 512, 'y' => 512, 'a' => 1, 'fileId' => $node->getId()],
            ),
            'file_url' => $this->urlGenerator->linkToRouteAbsolute(
                'files.View.showFile',
                ['fileid' => $node->getId()],
            ),
        ];
    }
}
