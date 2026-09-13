<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use Psr\Log\LoggerInterface;

class ImageEmbeddingBatchService
{
    public function __construct(
        private MediaLabContractService $contractService,
        private MediaLabClient $mediaLab,
        private MediaLabErrorPolicy $errorPolicy,
        private FileIndexingService $fileIndexing,
        private AppConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    public function process(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $contract = $this->contractService->getDefaultModelContract();
        if (!$this->supportsBatch($contract)) {
            return $this->processSingles($jobs);
        }

        $prepared = [];
        $results = [];
        try {
            foreach ($jobs as $job) {
                $jobId = (int)$job['id'];
                try {
                    $item = $this->fileIndexing->prepareImageForEmbedding($job, $contract);
                } catch (ExternalServiceException $e) {
                    $results[$jobId] = $this->exceptionResult($e, $job);
                    continue;
                } catch (\Throwable $e) {
                    $this->logger->warning('Media Embedding Service image preparation failed unexpectedly', [
                        'app' => 'media_embedding_connector',
                        'job_id' => $jobId,
                        'exception_class' => $e::class,
                    ]);
                    $results[$jobId] = [
                        'status' => IndexJobRepository::STATUS_FAILED,
                        'error' => 'image_preparation_failed',
                        'status_code' => null,
                        'retry' => false,
                    ];
                    continue;
                }
                if (($item['status'] ?? null) === 'ready') {
                    $prepared[] = $item;
                    continue;
                }
                $results[$jobId] = ['status' => (string)($item['status'] ?? IndexJobRepository::STATUS_FAILED)];
            }

            foreach ($this->chunkPreparedItems($prepared, $contract) as $chunk) {
                $results += $this->processChunk($chunk, false);
            }
            return $results;
        } finally {
            foreach ($prepared as $item) {
                $this->fileIndexing->cleanupPreparedImage($item);
            }
        }
    }

    /**
     * @param array<string, mixed> $contract
     */
    public function supportsBatch(array $contract): bool
    {
        $imageInput = is_array($contract['image_input'] ?? null) ? $contract['image_input'] : [];
        return (int)($imageInput['batch_max_items'] ?? 0) > 1;
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function processSingles(array $jobs): array
    {
        $results = [];
        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            try {
                $status = $this->fileIndexing->process($job);
                $results[$jobId] = ['status' => $status];
            } catch (ExternalServiceException $e) {
                $results[$jobId] = $this->exceptionResult($e, $job);
            }
        }
        return $results;
    }

    /**
     * @param list<array<string, mixed>> $prepared
     * @param array<string, mixed> $contract
     * @return list<list<array<string, mixed>>>
     */
    private function chunkPreparedItems(array $prepared, array $contract): array
    {
        $imageInput = is_array($contract['image_input'] ?? null) ? $contract['image_input'] : [];
        $maxItems = min(
            $this->config->getImageBatchSize(),
            max(1, (int)($imageInput['batch_max_items'] ?? $this->config->getImageBatchSize())),
        );
        $maxBytes = max(1, (int)($imageInput['batch_max_total_mb'] ?? 1_000)) * 1024 * 1024;
        $maxPixels = max(1, (int)($imageInput['batch_max_total_pixels'] ?? PHP_INT_MAX));

        $chunks = [];
        $chunk = [];
        $bytes = 0;
        $pixels = 0;
        foreach ($prepared as $item) {
            $itemBytes = (int)($item['size_bytes'] ?? 0);
            $itemPixels = (int)($item['pixel_count'] ?? 0);
            if (
                $chunk !== []
                && (count($chunk) >= $maxItems || $bytes + $itemBytes > $maxBytes || $pixels + $itemPixels > $maxPixels)
            ) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
                $pixels = 0;
            }
            $chunk[] = $item;
            $bytes += $itemBytes;
            $pixels += $itemPixels;
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }
        return $chunks;
    }

    /**
     * @param list<array<string, mixed>> $chunk
     * @return array<int, array<string, mixed>>
     */
    private function processChunk(array $chunk, bool $alreadySplit): array
    {
        $started = microtime(true);
        try {
            $response = $this->mediaLab->embedImageFiles(
                array_map(static fn (array $item): array => [
                    'path' => (string)$item['tmp_path'],
                    'mime_type' => (string)$item['mime_type'],
                    'extension' => self::extensionForMime((string)$item['mime_type']),
                ], $chunk),
                $this->config->getImageBatchRequestTimeout(),
            );
            $results = $this->mapBatchResponse($chunk, $response);
            $this->logBatch($chunk, $started, $results, 0);
            return $results;
        } catch (ExternalServiceException $e) {
            if (count($chunk) > 1 && $this->shouldSplitBatchFailure($chunk, $e, $alreadySplit)) {
                $mid = intdiv(count($chunk), 2);
                return $this->processChunk(array_slice($chunk, 0, $mid), true)
                    + $this->processChunk(array_slice($chunk, $mid), true);
            }
            if (count($chunk) === 1) {
                return $this->processSingles([(array)$chunk[0]['job']]);
            }

            $result = [];
            foreach ($chunk as $item) {
                $job = (array)$item['job'];
                $result[(int)$job['id']] = $this->exceptionResult($e, $job);
            }
            $this->logBatch($chunk, $started, $result, $this->maxAttempt($chunk));
            return $result;
        }
    }

    /**
     * @param list<array<string, mixed>> $chunk
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function mapBatchResponse(array $chunk, array $response): array
    {
        $results = [];
        $seen = [];
        foreach (($response['results'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $index = (int)($item['index'] ?? -1);
            if (!isset($chunk[$index])) {
                continue;
            }
            $job = (array)$chunk[$index]['job'];
            $seen[$index] = true;
            $results[(int)$job['id']] = [
                'status' => $this->fileIndexing->indexPreparedImage($chunk[$index], $item),
            ];
        }

        foreach (($response['failed'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $index = (int)($item['index'] ?? -1);
            if (!isset($chunk[$index])) {
                continue;
            }
            $job = (array)$chunk[$index]['job'];
            $seen[$index] = true;
            $results[(int)$job['id']] = $this->failureResult(
                (int)($item['status_code'] ?? 0),
                (string)($item['error'] ?? 'medialab_error'),
                $job,
                null,
                true,
                $chunk[$index],
            );
        }

        foreach ($chunk as $index => $item) {
            if (!isset($seen[$index])) {
                $job = (array)$item['job'];
                $results[(int)$job['id']] = [
                    'status' => IndexJobRepository::STATUS_FAILED,
                    'error' => 'missing_batch_result',
                    'status_code' => null,
                    'retry' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function exceptionResult(ExternalServiceException $e, array $job): array
    {
        return $this->failureResult($e->getCode(), $e->getPublicCode(), $job, $e->isRetryable(), true);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function failureResult(
        int $statusCode,
        string $errorCode,
        array $job,
        ?bool $retryable = null,
        bool $allowSkip = false,
        ?array $prepared = null,
    ): array
    {
        $policy = $this->errorPolicy->classify($statusCode, $errorCode);
        $retry = $retryable ?? (bool)$policy['retry'];
        if ($retry && (int)($job['attempts'] ?? 0) + 1 < ProcessIndexJobPolicy::MAX_ATTEMPTS) {
            return [
                'status' => 'retry',
                'error' => $errorCode,
                'status_code' => $statusCode > 0 ? $statusCode : null,
                'retry' => true,
            ];
        }
        if ($allowSkip && is_string($policy['skip_reason'] ?? null)) {
            if ($prepared !== null) {
                $this->fileIndexing->skipPreparedImage($prepared, (string)$policy['skip_reason']);
            }
            return [
                'status' => IndexJobRepository::STATUS_SKIPPED,
                'error' => (string)$policy['skip_reason'],
                'status_code' => $statusCode > 0 ? $statusCode : null,
                'retry' => false,
            ];
        }
        return [
            'status' => IndexJobRepository::STATUS_FAILED,
            'error' => $errorCode,
            'status_code' => $statusCode > 0 ? $statusCode : null,
            'retry' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $chunk
     */
    private function hasPriorAttempt(array $chunk): bool
    {
        foreach ($chunk as $item) {
            $job = (array)$item['job'];
            if ((int)($job['attempts'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string, mixed>> $chunk
     */
    private function shouldSplitBatchFailure(array $chunk, ExternalServiceException $e, bool $alreadySplit): bool
    {
        if ($alreadySplit || $this->hasPriorAttempt($chunk)) {
            return true;
        }

        return !$e->isRetryable() && in_array($e->getPublicCode(), [
            'invalid_image',
            'unsupported_image_type',
            'image_too_large',
            'image_pixel_limit_exceeded',
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $chunk
     */
    private function maxAttempt(array $chunk): int
    {
        $max = 0;
        foreach ($chunk as $item) {
            $job = (array)$item['job'];
            $max = max($max, (int)($job['attempts'] ?? 0));
        }
        return $max;
    }

    /**
     * @param list<array<string, mixed>> $chunk
     * @param array<int, array<string, mixed>> $results
     */
    private function logBatch(array $chunk, float $started, array $results, int $retryCount): void
    {
        $success = count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'indexed'));
        $failed = count($results) - $success;
        $bytes = array_sum(array_map(static fn (array $item): int => (int)($item['size_bytes'] ?? 0), $chunk));
        $durationMs = (int)round(((float)microtime(true) - $started) * 1000.0);
        $this->logger->info('Media Embedding Service image embedding batch processed', [
            'app' => 'media_embedding_connector',
            'batch_size' => count($chunk),
            'payload_bytes' => $bytes,
            'duration_ms' => $durationMs,
            'items_per_second' => $durationMs > 0
                ? round((float)count($chunk) / ((float)$durationMs / 1000.0), 3)
                : count($chunk),
            'success_count' => $success,
            'failed_count' => $failed,
            'retry_count' => $retryCount,
        ]);
    }

    private static function extensionForMime(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/tiff' => 'tif',
            'image/bmp' => 'bmp',
            default => 'img',
        };
    }
}

final class ProcessIndexJobPolicy
{
    public const MAX_ATTEMPTS = 5;
}
