<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\StateRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;

class IndexLifecycleService
{
    public const STATE_ACTIVE_CONTRACT = 'active_contract';
    public const STATE_WRITE_INDEX = 'write_index';
    public const STATE_SEARCH_INDEX = 'search_index';
    public const STATE_MODEL_CHANGE_PENDING = 'model_change_pending';
    public const STATE_BACKFILL_PAUSED = 'backfill_paused';

    public function __construct(
        private StateRepository $state,
        private MediaLabContractService $contractService,
        private IndexMappingFactory $mappingFactory,
        private ElasticsearchClient $elasticsearch,
        private AppConfig $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(bool $confirmModelChange = false): array
    {
        $contract = $this->contractService->getDefaultModelContract();
        $mapping = $this->mappingFactory->buildFromContract($contract);
        $current = $this->state->getJson(self::STATE_ACTIVE_CONTRACT);
        $changed = $current !== []
            && ($current['model_fingerprint'] ?? null) !== ($contract['model_fingerprint'] ?? null);

        if ($changed && !$confirmModelChange) {
            $this->config->setIndexingEnabled(false);
            $this->state->setJson(self::STATE_MODEL_CHANGE_PENDING, [
                'current' => $current,
                'detected' => $contract,
                'detected_at' => time(),
            ]);
            throw new ExternalServiceException(
                'Media Embedding Service model change requires administrator confirmation.',
                'model_change_confirmation_required',
            );
        }

        $indexName = (string)$mapping['index_name'];
        if (!$this->elasticsearch->indexExists($indexName)) {
            $this->elasticsearch->createIndex($indexName, $mapping['mapping']);
        }

        $this->state->set(self::STATE_WRITE_INDEX, $indexName);
        $this->state->setJson(self::STATE_ACTIVE_CONTRACT, $contract);
        $this->state->setJson(self::STATE_MODEL_CHANGE_PENDING, []);

        $searchIndex = $this->state->get(self::STATE_SEARCH_INDEX);
        if ($searchIndex === null || $searchIndex === '') {
            $this->elasticsearch->swapAlias($this->config->getIndexAlias(), $indexName);
            $this->state->set(self::STATE_SEARCH_INDEX, $indexName);
            $searchIndex = $indexName;
        }

        return [
            'contract' => $contract,
            'write_index' => $indexName,
            'search_index' => $searchIndex,
            'requires_reindex' => $changed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activateWriteIndex(): array
    {
        $writeIndex = (string)$this->state->get(self::STATE_WRITE_INDEX, '');
        if ($writeIndex === '') {
            throw new \RuntimeException('No prepared write index exists.');
        }

        $this->elasticsearch->swapAlias($this->config->getIndexAlias(), $writeIndex);
        $this->state->set(self::STATE_SEARCH_INDEX, $writeIndex);
        return ['active_index' => $writeIndex, 'alias' => $this->config->getIndexAlias()];
    }

    public function getWriteIndex(): string
    {
        $index = (string)$this->state->get(self::STATE_WRITE_INDEX, '');
        if ($index === '') {
            throw new ExternalServiceException('No connector-managed image index is prepared.', 'index_not_prepared');
        }
        return $index;
    }

    public function getSearchAlias(): string
    {
        return $this->config->getIndexAlias();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [
            'active_contract' => $this->state->getJson(self::STATE_ACTIVE_CONTRACT),
            'write_index' => $this->state->get(self::STATE_WRITE_INDEX),
            'search_index' => $this->state->get(self::STATE_SEARCH_INDEX),
            'model_change_pending' => $this->state->getJson(self::STATE_MODEL_CHANGE_PENDING),
            'backfill_paused' => $this->isBackfillPaused(),
        ];
    }

    public function setBackfillPaused(bool $paused): void
    {
        $this->state->set(self::STATE_BACKFILL_PAUSED, $paused ? '1' : '0');
    }

    public function isBackfillPaused(): bool
    {
        return $this->state->get(self::STATE_BACKFILL_PAUSED, '0') === '1';
    }
}
