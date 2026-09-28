<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\ConnectionConfigService;
use OCA\MediaEmbeddingConnector\Service\ElasticsearchClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

class ElasticsearchClientTest extends TestCase
{
    public function testSearchAllowsMoreThanFiveHundredResults(): void
    {
        $captured = [];
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('{"hits":{"hits":[]}}');

        $client = $this->createMock(IClient::class);
        $client->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$captured, $response): IResponse {
                $captured = compact('method', 'url', 'options');
                return $response;
            });
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $connection = $this->createMock(ConnectionConfigService::class);
        $connection->method('getElasticsearchConnection')->willReturn([
            'url' => 'https://elasticsearch.example.com',
            'username' => '',
            'password' => '',
            'api_key' => '',
            'verify_tls' => true,
            'allow_private_networks' => false,
        ]);

        $service = new ElasticsearchClient($connection, $clientService);
        $service->search('nc_media_embeddings_search', [1.0, 0.0], 600);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://elasticsearch.example.com/nc_media_embeddings_search/_search', $captured['url']);
        $body = json_decode((string)$captured['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(600, $body['size']);
        self::assertSame(600, $body['knn']['k']);
        self::assertSame(2000, $body['knn']['num_candidates']);
    }

    public function testCandidateCountGrowsOnlyWhenKExceedsFixedBudget(): void
    {
        $captured = [];
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('{"hits":{"hits":[]}}');

        $client = $this->createMock(IClient::class);
        $client->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$captured, $response): IResponse {
                $captured = compact('method', 'url', 'options');
                return $response;
            });
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $connection = $this->createMock(ConnectionConfigService::class);
        $connection->method('getElasticsearchConnection')->willReturn([
            'url' => 'https://elasticsearch.example.com',
            'username' => '',
            'password' => '',
            'api_key' => '',
            'verify_tls' => true,
            'allow_private_networks' => false,
        ]);

        $service = new ElasticsearchClient($connection, $clientService);
        $service->search('nc_media_embeddings_search', [1.0, 0.0], 2500);

        $body = json_decode((string)$captured['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2500, $body['knn']['k']);
        self::assertSame(2500, $body['knn']['num_candidates']);
    }
}
