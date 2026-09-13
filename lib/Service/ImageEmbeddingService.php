<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Files\IMimeTypeDetector;

class ImageEmbeddingService
{
    public function __construct(
        private MediaLabClient $client,
        private MediaLabContractService $contractService,
        private ImageEligibilityService $eligibilityService,
        private VectorValidator $vectorValidator,
        private IMimeTypeDetector $mimeTypeDetector,
    ) {
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return list<float>
     */
    public function embedUploadedFile(array $uploadedFile): array
    {
        $embedded = $this->embedAndValidateUploadedFile($uploadedFile);
        return array_map(static fn (mixed $value): float => (float)$value, $embedded['vector']);
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array<string, mixed>
     */
    public function embedUploadedFileForProbe(array $uploadedFile): array
    {
        $embedded = $this->embedAndValidateUploadedFile($uploadedFile);
        $response = $embedded['response'];
        $vector = $embedded['vector'];

        return [
            'request_id' => $response['request_id'] ?? null,
            'media_type' => $response['media_type'] ?? null,
            'model_id' => $response['model_id'] ?? null,
            'model_fingerprint' => $response['model_fingerprint'] ?? null,
            'technical_metadata' => $response['technical_metadata'] ?? null,
            'vector' => $this->vectorValidator->summarize($vector),
        ];
    }

    /**
     * @param array<string, mixed> $uploadedFile
     * @return array{response:array<string, mixed>, vector:list<float|int>}
     */
    private function embedAndValidateUploadedFile(array $uploadedFile): array
    {
        $tmpName = (string)($uploadedFile['tmp_name'] ?? '');
        if ($tmpName === '' || !is_readable($tmpName)) {
            throw new \InvalidArgumentException('invalid_image_upload');
        }

        $detectedMime = $this->mimeTypeDetector->detectContent($tmpName);
        $mimeType = ImageEligibilityService::normalizeMimeType($detectedMime);
        $size = filesize($tmpName);
        $sizeBytes = is_int($size) ? $size : null;
        $contract = $this->contractService->getDefaultModelContract();
        $eligibility = $this->eligibilityService->evaluate($mimeType, $sizeBytes, null, $contract);
        if ($eligibility['allowed'] !== true) {
            throw new \InvalidArgumentException((string)($eligibility['reason'] ?? 'unsupported_image_type'));
        }

        $imageInfo = @getimagesize($tmpName);
        if (!is_array($imageInfo) || $imageInfo[0] <= 0 || $imageInfo[1] <= 0) {
            throw new \InvalidArgumentException('invalid_image_upload');
        }
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        if ($width > intdiv(PHP_INT_MAX, $height)) {
            throw new \InvalidArgumentException('image_pixel_limit_exceeded');
        }
        $pixelCount = $width * $height;
        $eligibility = $this->eligibilityService->evaluate($mimeType, $sizeBytes, $pixelCount, $contract);
        if ($eligibility['allowed'] !== true) {
            throw new \InvalidArgumentException((string)($eligibility['reason'] ?? 'unsupported_image_type'));
        }

        $response = $this->client->embedImageFile($tmpName, $mimeType);
        $vector = $response['image_vector'] ?? null;
        $embeddingDim = (int)($contract['embedding_dim'] ?? 0);
        $normalized = (bool)($contract['normalized'] ?? false);
        $errors = $this->vectorValidator->validate($vector, $embeddingDim, $normalized);
        if ($errors !== [] || !is_array($vector)) {
            throw new ExternalServiceException('Media Embedding Service image vector failed validation.', 'invalid_image_vector');
        }

        $vector = $this->vectorValidator->normalizeValidated($vector);

        return [
            'response' => $response,
            'vector' => $vector,
        ];
    }
}
