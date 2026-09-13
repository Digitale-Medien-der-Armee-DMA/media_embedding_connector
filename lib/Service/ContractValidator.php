<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class ContractValidator
{
    private const REQUIRED_OPERATIONS = ['embed_text', 'embed_image'];

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public function validateDefaultModelContract(array $contract): array
    {
        $errors = [];

        foreach (['model_id', 'model_name', 'model_version', 'model_fingerprint', 'similarity'] as $field) {
            if (!is_string($contract[$field] ?? null) || $contract[$field] === '') {
                $errors[] = $field . ' must be a non-empty string.';
            }
        }

        if (!is_int($contract['embedding_dim'] ?? null) || $contract['embedding_dim'] <= 0) {
            $errors[] = 'embedding_dim must be a positive integer.';
        }

        if (($contract['normalized'] ?? null) !== true) {
            $errors[] = 'normalized must be true.';
        }

        if (($contract['similarity'] ?? null) !== 'cosine') {
            $errors[] = 'similarity must be cosine.';
        }

        $operations = is_array($contract['operations'] ?? null) ? $contract['operations'] : [];
        foreach (self::REQUIRED_OPERATIONS as $operation) {
            if (!in_array($operation, $operations, true)) {
                $errors[] = 'operation ' . $operation . ' is missing.';
            }
        }

        $textInput = is_array($contract['text_input'] ?? null) ? $contract['text_input'] : [];
        if (!is_int($textInput['max_chars'] ?? null) || $textInput['max_chars'] <= 0) {
            $errors[] = 'text_input.max_chars must be a positive integer.';
        }

        $imageInput = is_array($contract['image_input'] ?? null) ? $contract['image_input'] : [];
        if (!is_int($imageInput['max_upload_mb'] ?? null) || $imageInput['max_upload_mb'] <= 0) {
            $errors[] = 'image_input.max_upload_mb must be a positive integer.';
        }
        if (!is_int($imageInput['max_pixels'] ?? null) || $imageInput['max_pixels'] <= 0) {
            $errors[] = 'image_input.max_pixels must be a positive integer.';
        }
        if (!is_array($imageInput['supported_image_mime_types'] ?? null) || $imageInput['supported_image_mime_types'] === []) {
            $errors[] = 'image_input.supported_image_mime_types must not be empty.';
        }

        return $errors;
    }
}
