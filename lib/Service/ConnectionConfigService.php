<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class ConnectionConfigService
{
    public function __construct(
        private AppConfig $config,
    ) {
    }

    /**
     * @return array{base_url:string, token:string, allow_private_networks:bool}
     */
    public function getMediaLabConnection(
        ?string $originUrl = null,
        ?string $token = null,
        ?bool $allowPrivateNetworks = null,
    ): array {
        $resolvedOrigin = trim((string)$originUrl);
        if ($resolvedOrigin === '') {
            $resolvedOrigin = $this->config->getMediaLabOriginUrl();
        }

        $resolvedToken = trim((string)$token);
        if ($resolvedToken === '') {
            $resolvedToken = $this->config->getMediaLabToken();
        }

        return [
            'base_url' => $this->config->buildMediaLabApiBaseUrl($resolvedOrigin),
            'token' => $resolvedToken,
            'allow_private_networks' => $allowPrivateNetworks ?? $this->config->allowsPrivateNetworks(),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{
     *   url:string,
     *   username:string,
     *   password:string,
     *   api_key:string,
     *   verify_tls:bool,
     *   allow_private_networks:bool
     * }
     */
    public function getElasticsearchConnection(array $overrides = []): array
    {
        $saved = [
            'url' => $this->config->getElasticsearchUrl(),
            'username' => $this->config->getElasticsearchUsername(),
            'password' => $this->config->getElasticsearchPassword(),
            'api_key' => $this->config->getElasticsearchApiKey(),
        ];
        $base = $saved;

        foreach (['url', 'username', 'password', 'api_key'] as $field) {
            $submitted = trim((string)($overrides[$field] ?? ''));
            if ($submitted !== '') {
                $base[$field] = $submitted;
            }
        }

        return [
            'url' => rtrim((string)$base['url'], '/'),
            'username' => (string)$base['username'],
            'password' => (string)$base['password'],
            'api_key' => (string)$base['api_key'],
            'verify_tls' => array_key_exists('verify_tls', $overrides)
                ? (bool)$overrides['verify_tls']
                : $this->config->shouldVerifyElasticsearchTls(),
            'allow_private_networks' => array_key_exists('allow_private_networks', $overrides)
                ? (bool)$overrides['allow_private_networks']
                : $this->config->allowsPrivateNetworks(),
        ];
    }
}
