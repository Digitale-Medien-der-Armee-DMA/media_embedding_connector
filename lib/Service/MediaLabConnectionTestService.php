<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;

class MediaLabConnectionTestService
{
    public function __construct(
        private ConnectionConfigService $connectionConfig,
        private MediaLabContractService $contractService,
        private MediaLabClient $client,
        private VectorValidator $vectorValidator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $originUrl,
        string $token,
        int $iterations,
        bool $includeTextEmbedding,
        bool $allowPrivateNetworks,
    ): array {
        $iterations = max(1, min(20, $iterations));
        $connection = $this->connectionConfig->getMediaLabConnection(
            $originUrl,
            $token,
            $allowPrivateNetworks,
        );
        $results = [];
        $successful = 0;
        $contract = null;

        for ($index = 0; $index < $iterations; $index++) {
            $started = hrtime(true);
            try {
                $contract = $this->contractService->getDefaultModelContract($connection);
                $health = $this->contractService->getHealth($connection);
                $embedding = null;
                if ($includeTextEmbedding && $index === 0) {
                    $response = $this->client->embedText('Nextcloud Media Embedding Service connection test', $connection);
                    $vector = $response['query_vector'] ?? null;
                    $errors = $this->vectorValidator->validate(
                        $vector,
                        (int)$contract['embedding_dim'],
                        (bool)$contract['normalized'],
                    );
                    if ($errors !== [] || !is_array($vector)) {
                        throw new ExternalServiceException(
                            'Media Embedding Service returned an invalid text vector.',
                            'invalid_text_vector',
                        );
                    }
                    $vector = $this->vectorValidator->normalizeValidated($vector);
                    $embedding = $this->vectorValidator->summarize($vector);
                }

                $successful++;
                $results[] = [
                    'iteration' => $index + 1,
                    'success' => true,
                    'latency_ms' => $this->elapsedMs($started),
                    'health' => $health['status'] ?? $health['success'] ?? true,
                    'request_id' => $health['request_id'] ?? $contract['request_id'] ?? null,
                    'text_embedding' => $embedding,
                ];
            } catch (ExternalServiceException $e) {
                $results[] = [
                    'iteration' => $index + 1,
                    'success' => false,
                    'latency_ms' => $this->elapsedMs($started),
                    'error_code' => $e->getPublicCode(),
                    'retryable' => $e->isRetryable(),
                ];
            } catch (\Throwable) {
                $results[] = [
                    'iteration' => $index + 1,
                    'success' => false,
                    'latency_ms' => $this->elapsedMs($started),
                    'error_code' => 'medialab_test_failed',
                    'retryable' => false,
                ];
            }
        }

        $latencies = array_column($results, 'latency_ms');
        if ($latencies === []) {
            throw new \LogicException('At least one connection-test iteration is required.');
        }
        return [
            'success' => $successful === $iterations,
            'iterations' => $iterations,
            'successful' => $successful,
            'success_rate' => round($successful / $iterations, 3),
            'latency_ms' => [
                'min' => min($latencies),
                'max' => max($latencies),
                'average' => round((float)array_sum($latencies) / (float)count($latencies), 1),
            ],
            'model_fingerprint' => is_array($contract) ? $contract['model_fingerprint'] ?? null : null,
            'embedding_dim' => is_array($contract) ? $contract['embedding_dim'] ?? null : null,
            'results' => $results,
        ];
    }

    private function elapsedMs(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 1);
    }
}
