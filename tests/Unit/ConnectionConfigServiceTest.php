<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ConnectionConfigService;
use PHPUnit\Framework\TestCase;

class ConnectionConfigServiceTest extends TestCase
{
    public function testLegacySourceCannotSelectAnotherAppsCredentials(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getElasticsearchUrl')->willReturn('https://search.example.com/');
        $config->method('getElasticsearchUsername')->willReturn('connector');
        $config->method('getElasticsearchPassword')->willReturn('saved-secret');
        $config->method('getElasticsearchApiKey')->willReturn('');
        $config->method('shouldVerifyElasticsearchTls')->willReturn(true);
        $service = new ConnectionConfigService($config);
        $result = $service->getElasticsearchConnection(['source' => 'inherited', 'password' => '']);
        self::assertSame('https://search.example.com', $result['url']);
        self::assertSame('connector', $result['username']);
        self::assertSame('saved-secret', $result['password']);
        self::assertTrue($result['verify_tls']);
        $result = $service->getElasticsearchConnection(['url' => 'https://other.example.com', 'password' => 'new-secret']);
        self::assertSame('https://other.example.com', $result['url']);
        self::assertSame('new-secret', $result['password']);
    }
}
