<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Http\Client\IClientService;

class MediaLabClient
{
    public function __construct(
        private ConnectionConfigService $connectionConfig,
        private IClientService $clientService,
        private RequestIdFactory $requestIdFactory,
        private MediaLabErrorPolicy $errorPolicy,
    ) {
    }

    /**
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>
     */
    public function getModels(?array $connection = null): array
    {
        return $this->requestJson('GET', '/models', 'models:read', 5, [], $connection);
    }

    /**
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>
     */
    public function getHealth(?array $connection = null): array
    {
        return $this->requestJson('GET', '/health', 'health:read', 5, [], $connection);
    }

    /**
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>
     */
    public function embedText(string $text, ?array $connection = null): array
    {
        return $this->requestJson('POST', '/embed/text', 'embed:text', 15, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['text' => trim($text)], JSON_THROW_ON_ERROR),
        ], $connection);
    }

    /**
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>
     */
    public function embedImageFile(
        string $filePath,
        ?string $mimeType = null,
        ?array $connection = null,
    ): array {
        if (!is_readable($filePath)) {
            throw new ExternalServiceException('Image file is not readable.', 'image_not_readable');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ExternalServiceException('Image file could not be opened.', 'image_not_readable');
        }

        $part = [
            'name' => 'image',
            'contents' => $handle,
            'filename' => 'image',
        ];
        if ($mimeType !== null && $mimeType !== '') {
            $part['headers'] = ['Content-Type' => $mimeType];
        }

        return $this->requestJson('POST', '/embed/image', 'embed:image', 60, [
            'multipart' => [$part],
        ], $connection);
    }

    /**
     * @param list<array{path:string,mime_type?:?string,extension?:?string}> $images
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>
     */
    public function embedImageFiles(
        array $images,
        int $timeoutSeconds,
        ?array $connection = null,
    ): array {
        $parts = [];
        foreach ($images as $index => $image) {
            $filePath = (string)($image['path'] ?? '');
            if (!is_readable($filePath)) {
                throw new ExternalServiceException('Image file is not readable.', 'image_not_readable');
            }

            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                throw new ExternalServiceException('Image file could not be opened.', 'image_not_readable');
            }

            $part = [
                'name' => 'images',
                'contents' => $handle,
                'filename' => 'image_' . $index . $this->safeImageExtension($image['extension'] ?? null),
            ];
            $mimeType = (string)($image['mime_type'] ?? '');
            if ($mimeType !== '') {
                $part['headers'] = ['Content-Type' => $mimeType];
            }
            $parts[] = $part;
        }

        return $this->requestJson('POST', '/embed/image/batch', 'embed:image:batch', $timeoutSeconds, [
            'multipart' => $parts,
        ], $connection);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $connection
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $path,
        string $requestKind,
        int $timeoutSeconds,
        array $options = [],
        ?array $connection = null,
    ): array {
        $connection ??= $this->connectionConfig->getMediaLabConnection();
        $apiBaseUrl = rtrim((string)($connection['base_url'] ?? ''), '/');
        $token = trim((string)($connection['token'] ?? ''));
        if ($apiBaseUrl === '' || $token === '') {
            throw new ExternalServiceException(
                'Media Embedding Service URL and token must be configured.',
                'medialab_not_configured',
            );
        }

        $requestOptions = array_replace_recursive([
            'timeout' => $timeoutSeconds,
            'connect_timeout' => 5,
            'http_errors' => false,
            'allow_redirects' => false,
            'nextcloud' => [
                'allow_local_address' => (bool)($connection['allow_private_networks'] ?? false),
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'X-Request-ID' => $this->requestIdFactory->create($requestKind),
                'Cache-Control' => 'no-store',
                'Accept' => 'application/json',
            ],
        ], $options);

        try {
            $response = $this->clientService->newClient()->request(
                strtoupper($method),
                $apiBaseUrl . $path,
                $requestOptions,
            );
        } catch (\Throwable $e) {
            throw new ExternalServiceException(
                'Media Embedding Service request could not be completed.',
                'medialab_unreachable',
                true,
                null,
                0,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw new ExternalServiceException(
                'Media Embedding Service returned an invalid response.',
                'medialab_invalid_response',
                $statusCode >= 500,
                null,
                $statusCode,
            );
        }

        if ($statusCode < 200 || $statusCode >= 300 || ($decoded['success'] ?? true) === false) {
            $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'medialab_error';
            $policy = $this->errorPolicy->classify($statusCode, $error);
            throw new ExternalServiceException(
                'Media Embedding Service rejected the request.',
                $error,
                (bool)$policy['retry'],
                is_string($policy['skip_reason']) ? $policy['skip_reason'] : null,
                $statusCode,
            );
        }

        return $decoded;
    }

    private function safeImageExtension(?string $extension): string
    {
        $extension = strtolower(trim((string)$extension));
        if ($extension === '' || !preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
            return '';
        }

        return '.' . $extension;
    }
}
