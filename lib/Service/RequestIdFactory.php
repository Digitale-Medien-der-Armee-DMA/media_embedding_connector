<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class RequestIdFactory
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/';

    public function create(string $kind): string
    {
        $safeKind = preg_replace('/[^A-Za-z0-9_.:-]/', '-', $kind) ?: 'request';
        $requestId = 'nc:' . trim($safeKind, '-_.:') . ':' . bin2hex(random_bytes(8));

        if (!$this->isValid($requestId)) {
            return 'nc:request:' . bin2hex(random_bytes(8));
        }

        return $requestId;
    }

    public function isValid(string $requestId): bool
    {
        return preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }
}
