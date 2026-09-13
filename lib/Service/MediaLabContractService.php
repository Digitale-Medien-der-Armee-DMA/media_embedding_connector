<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class MediaLabContractService
{
    public function __construct(
        private MediaLabClient $client,
        private ContractValidator $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultModelContract(?array $connection = null): array
    {
        $modelsResponse = $this->client->getModels($connection);
        $models = $modelsResponse['models'] ?? [];
        if (!is_array($models) || $models === []) {
            throw new \RuntimeException('Media Embedding Service /models response does not contain models.');
        }

        $defaultModelId = $modelsResponse['default_model_id'] ?? null;
        $defaultModel = null;
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            if ($defaultModelId === null || ($model['model_id'] ?? null) === $defaultModelId) {
                $defaultModel = $model;
                break;
            }
        }

        if ($defaultModel === null) {
            throw new \RuntimeException('Media Embedding Service default model is not present in /models response.');
        }

        $imageInput = is_array($defaultModel['image_input'] ?? null) ? $defaultModel['image_input'] : [];
        $guaranteed = $this->stringList($imageInput['guaranteed_mime_types'] ?? []);
        $runtimeDetected = $this->stringList($imageInput['runtime_detected_mime_types'] ?? []);
        $optional = $this->stringList($imageInput['optional_mime_types'] ?? []);
        $supported = array_values(array_unique(array_merge($guaranteed, $runtimeDetected)));
        sort($supported);

        $contract = [
            'contract_version' => $modelsResponse['contract_version'] ?? null,
            'request_id' => $modelsResponse['request_id'] ?? null,
            'default_model_id' => $modelsResponse['default_model_id'] ?? null,
            'model_id' => $defaultModel['model_id'] ?? null,
            'model_name' => $defaultModel['model_name'] ?? null,
            'model_version' => $defaultModel['model_version'] ?? null,
            'model_fingerprint' => $defaultModel['model_fingerprint'] ?? null,
            'embedding_dim' => $defaultModel['embedding_dim'] ?? null,
            'normalized' => $defaultModel['normalized'] ?? null,
            'similarity' => $defaultModel['similarity'] ?? null,
            'operations' => $this->stringList($defaultModel['operations'] ?? []),
            'text_input' => $defaultModel['text_input'] ?? [],
            'image_input' => [
                'max_upload_mb' => $imageInput['max_upload_mb'] ?? null,
                'max_pixels' => $imageInput['max_pixels'] ?? null,
                'guaranteed_mime_types' => $guaranteed,
                'runtime_detected_mime_types' => $runtimeDetected,
                'optional_mime_types' => $optional,
                'supported_image_mime_types' => $supported,
                'batch_max_items' => $this->positiveIntOrNull($imageInput['batch_max_items'] ?? null),
                'batch_max_total_mb' => $this->positiveIntOrNull($imageInput['batch_max_total_mb'] ?? null),
                'batch_max_total_pixels' => $this->positiveIntOrNull($imageInput['batch_max_total_pixels'] ?? null),
                'normalize_max_edge' => $this->positiveIntOrNull($imageInput['normalize_max_edge'] ?? null),
            ],
        ];

        $errors = $this->validator->validateDefaultModelContract($contract);
        if ($errors !== []) {
            throw new \RuntimeException('Media Embedding Service model contract is not valid: ' . implode(' ', $errors));
        }

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealth(?array $connection = null): array
    {
        return $this->client->getHealth($connection);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }
        return null;
    }
}
