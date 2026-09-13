<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class TextEmbeddingService
{
    public function __construct(
        private MediaLabClient $client,
        private MediaLabContractService $contractService,
        private VectorValidator $vectorValidator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function embedTextForProbe(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Text query must not be empty.');
        }

        $contract = $this->contractService->getDefaultModelContract();
        $textInput = is_array($contract['text_input'] ?? null) ? $contract['text_input'] : [];
        $maxChars = is_int($textInput['max_chars'] ?? null) ? $textInput['max_chars'] : 0;
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            throw new \RuntimeException('Text query exceeds the Media Embedding Service max_chars limit.');
        }

        $response = $this->client->embedText($text);
        $vector = $response['query_vector'] ?? null;
        $embeddingDim = (int)($contract['embedding_dim'] ?? 0);
        $normalized = (bool)($contract['normalized'] ?? false);
        $errors = $this->vectorValidator->validate($vector, $embeddingDim, $normalized);
        if ($errors !== [] || !is_array($vector)) {
            throw new \RuntimeException('Media Embedding Service text vector failed validation: ' . implode(' ', $errors));
        }

        $vector = $this->vectorValidator->normalizeValidated($vector);

        return [
            'request_id' => $response['request_id'] ?? null,
            'media_type' => $response['media_type'] ?? null,
            'model_id' => $response['model_id'] ?? null,
            'model_fingerprint' => $response['model_fingerprint'] ?? null,
            'text_length' => mb_strlen($text),
            'vector' => $this->vectorValidator->summarize($vector),
        ];
    }
}
