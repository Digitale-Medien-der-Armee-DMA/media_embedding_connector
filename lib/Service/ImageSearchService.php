<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\SearchSessionRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;

class ImageSearchService
{
    public const EXACT_SCOPE_LIMIT = 10000;
    public const SESSION_RESULT_LIMIT = 500;

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
        private VisibleFileScope $visibleFileScope,
        private SearchSessionRepository $sessions,
    ) {}

    /** @return array<string, mixed> */
    public function searchText(string $userId, string $query, int $limit, int $offset): array
    {
        $this->requireInitialOffset($offset);
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Search query must not be empty.');
        }
        $context = $this->searchContext();
        $contract = $this->requireCompatibleDefaultModel($context['contract']);
        $maxChars = (int)($contract['text_input']['max_chars'] ?? 0);
        if ($maxChars > 0 && mb_strlen($query) > $maxChars) {
            throw new \InvalidArgumentException('Search query is too long.');
        }
        $response = $this->mediaLab->embedText($query);
        $this->requireMatchingModel($response, $context['contract']);
        $vector = $this->validatedVector($response['query_vector'] ?? null, $context['contract']);
        return $this->startSession($userId, $vector, $limit, $context);
    }

    /** @return array<string, mixed> */
    public function searchSimilar(string $userId, string $fileId, int $limit, int $offset): array
    {
        $this->requireInitialOffset($offset);
        $this->requireVisibleNode($userId, $fileId);
        $context = $this->searchContext();
        $vector = $this->elasticsearch->getDocumentVector(
            $context['index'], $fileId, (string)$context['contract']['model_fingerprint'],
        );
        if ($vector === null) {
            throw new ExternalServiceException('Image is not indexed.', 'image_not_indexed');
        }
        return $this->startSession($userId, $this->validatedVector($vector, $context['contract']), $limit, $context, $fileId);
    }

    /** @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public function searchUploadedImage(string $userId, array $uploadedFile, int $limit, int $offset): array
    {
        $this->requireInitialOffset($offset);
        $context = $this->searchContext();
        $contract = $this->requireCompatibleDefaultModel($context['contract']);
        $vector = $this->imageEmbeddingService->embedUploadedFileForSearch($uploadedFile, $contract);
        return $this->startSession($userId, $this->validatedVector($vector, $context['contract']), $limit, $context);
    }

    /** Subsequent pages never re-embed, enumerate a scope, or query ES.
     * @return array<string, mixed>
     */
    public function searchPage(string $userId, string $sessionId, int $limit, int $offset): array
    {
        return $this->page($userId, $this->sessions->load($userId, $sessionId), $limit, $offset);
    }

    /** Resolve the actual alias target, not the possibly newer write index.
     * @return array{index:string, contract:array<string, mixed>}
     */
    private function searchContext(): array
    {
        $index = $this->elasticsearch->resolveSearchIndex($this->indexLifecycle->getSearchAlias());
        $contract = $this->elasticsearch->getIndexContract($index);
        if (!is_string($contract['model_id'] ?? null) || $contract['model_id'] === ''
            || !is_string($contract['model_fingerprint'] ?? null) || $contract['model_fingerprint'] === ''
            || (int)($contract['embedding_dim'] ?? 0) <= 0
            || ($contract['normalized'] ?? null) !== true || ($contract['similarity'] ?? null) !== 'cosine') {
            throw new ExternalServiceException('Search index model identity is unavailable.', 'search_model_unknown');
        }
        return ['index' => $index, 'contract' => $contract];
    }

    /** The adapter exposes only default-model inference. Reject drift instead
     * of inventing an unsupported model-selection API parameter.
     * @param array<string, mixed> $indexContract
     * @return array<string, mixed>
     */
    private function requireCompatibleDefaultModel(array $indexContract): array
    {
        $contract = $this->contractService->getDefaultModelContract();
        $this->requireMatchingModel($contract, $indexContract);
        foreach (['embedding_dim', 'normalized', 'similarity'] as $field) {
            if (($contract[$field] ?? null) !== ($indexContract[$field] ?? null)) {
                throw new ExternalServiceException('Embedding contract differs from the search index.', 'search_model_mismatch');
            }
        }
        return $contract;
    }

    /** @param array<string, mixed> $response
     * @param array<string, mixed> $contract
     */
    private function requireMatchingModel(array $response, array $contract): void
    {
        if (($response['model_id'] ?? null) !== ($contract['model_id'] ?? null)
            || ($response['model_fingerprint'] ?? null) !== ($contract['model_fingerprint'] ?? null)) {
            throw new ExternalServiceException('Embedding model differs from the search index.', 'search_model_mismatch');
        }
    }

    /** @param array<string, mixed> $contract
     * @return list<float|int>
     */
    private function validatedVector(mixed $vector, array $contract): array
    {
        if (!is_array($vector) || $this->vectorValidator->validate(
            $vector, (int)$contract['embedding_dim'], (bool)$contract['normalized'],
        ) !== []) {
            throw new ExternalServiceException('Query vector failed validation.', 'invalid_query_vector');
        }
        return $this->vectorValidator->normalizeValidated($vector);
    }

    private function requireInitialOffset(int $offset): void
    {
        if ($offset !== 0) {
            throw new \InvalidArgumentException('Use the search session endpoint for subsequent pages.');
        }
    }

    /** Build one bounded ranking from every scope block at one ES point in time.
     * The live scope is deliberately rebuilt for every NEW search: third-party
     * ACL/mount changes cannot silently leave a cached permission set stale.
     * @param list<float|int> $vector
     * @param array{index:string, contract:array<string, mixed>} $context
     * @return array<string, mixed>
     */
    private function startSession(string $userId, array $vector, int $limit, array $context, ?string $referenceId = null): array
    {
        $scope = $this->visibleFileScope->batches($userId);
        $scope->rewind();
        $first = $scope->valid() ? ($scope->current() ?? []) : [];
        if ($scope->valid()) {
            $scope->next();
        }
        // Look ahead before choosing the method: the complete scope, not
        // an individual filter block, determines whether exact search is cheap.
        $exact = !$scope->valid() && count($first) <= self::EXACT_SCOPE_LIMIT;
        $candidates = [];
        $snapshot = null;
        try {
            if ($first !== []) {
                $snapshot = $this->elasticsearch->openSearchSnapshot($context['index']);
                $block = $first;
                while (true) {
                    $hits = $this->elasticsearch->search(
                        $context['index'], $vector, self::SESSION_RESULT_LIMIT + 1,
                        $block, $referenceId, $exact,
                        (string)$context['contract']['model_fingerprint'], $snapshot,
                    );
                    foreach ($hits as $hit) {
                        $candidates[$hit['file_id']] = $hit;
                    }
                    uasort($candidates, static fn (array $a, array $b): int =>
                        ($b['score'] <=> $a['score']) ?: strcmp($a['file_id'], $b['file_id']));
                    $candidates = array_slice($candidates, 0, self::SESSION_RESULT_LIMIT + 1, true);
                    if (!$scope->valid()) {
                        break;
                    }
                    $block = $scope->current() ?? [];
                    $scope->next();
                }
            }
        } finally {
            if ($snapshot !== null) {
                $this->elasticsearch->closeSearchSnapshot($snapshot);
            }
        }
        $ranked = array_values($candidates);
        if ($referenceId !== null) {
            array_unshift($ranked, ['file_id' => $referenceId, 'score' => 1.0, 'is_reference' => true]);
        }
        $payload = [
            'candidates' => array_slice($ranked, 0, self::SESSION_RESULT_LIMIT),
            'search_mode' => $exact ? 'exact' : 'ann',
            'index' => $context['index'],
            'model_fingerprint' => $context['contract']['model_fingerprint'],
            'reference_id' => $referenceId,
            'result_limit_reached' => count($ranked) > self::SESSION_RESULT_LIMIT,
        ];
        $page = $this->page($userId, $payload + ['session_id' => '', 'expires_at' => 0], $limit, 0);
        return array_replace($page, $this->sessions->create($userId, $payload));
    }

    /** Cursor addresses the immutable ranking, not the number of visible hits.
     * Revoked/deleted files therefore cannot shift or truncate later pages.
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function page(string $userId, array $session, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $candidates = $session['candidates'];
        if ($offset < 0 || $offset > count($candidates)) {
            throw new \InvalidArgumentException('Search cursor is invalid.');
        }
        if (is_string($session['reference_id'] ?? null)) {
            $this->requireVisibleNode($userId, $session['reference_id']);
        }
        $results = [];
        $next = $offset;
        $more = false;
        for ($cursor = $offset; $cursor < count($candidates); ++$cursor) {
            $candidate = $candidates[$cursor];
            $node = $this->findVisibleNode($userId, $candidate['file_id']);
            if (!$node instanceof File || !$this->eligibilityService->isIndexingCandidate($node->getMimeType(), $node->getName())) {
                continue;
            }
            if (count($results) === $limit) {
                $more = true;
                break;
            }
            $results[] = $this->serializeResult($userId, $node, $candidate['score'], (bool)($candidate['is_reference'] ?? false));
            $next = $cursor + 1;
        }
        return [
            'results' => $results, 'offset' => $offset, 'limit' => $limit,
            'has_more' => $more, 'next_offset' => $more ? $next : null,
            'session_id' => $session['session_id'], 'expires_at' => $session['expires_at'],
            'search_mode' => $session['search_mode'],
            'result_limit_reached' => $session['result_limit_reached'],
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
        } catch (NotFoundException) {
            return null;
        } catch (\Throwable $e) {
            throw new ExternalServiceException('File permissions could not be resolved.', 'search_scope_unavailable', true, null, 0, $e);
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
