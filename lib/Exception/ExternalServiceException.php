<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Exception;

class ExternalServiceException extends \RuntimeException
{
    public function __construct(
        string $message,
        private string $publicCode,
        private bool $retryable = false,
        private ?string $skipReason = null,
        int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getPublicCode(): string
    {
        return $this->publicCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getSkipReason(): ?string
    {
        return $this->skipReason;
    }
}
