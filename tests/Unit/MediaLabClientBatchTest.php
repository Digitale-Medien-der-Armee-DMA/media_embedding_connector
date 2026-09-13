<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\ConnectionConfigService;
use OCA\MediaEmbeddingConnector\Service\MediaLabClient;
use OCA\MediaEmbeddingConnector\Service\MediaLabErrorPolicy;
use OCA\MediaEmbeddingConnector\Service\RequestIdFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

class MediaLabClientBatchTest extends TestCase
{
    public function testImageBatchMultipartUsesGenericRepeatedImageFields(): void
    {
        $captured = [];
        $client = $this->createMock(IClient::class);
        $client->method('request')->willReturnCallback(function (string $method, string $url, array $options) use (&$captured): IResponse {
            $captured = compact('method', 'url', 'options');
            $response = $this->createMock(IResponse::class);
            $response->method('getStatusCode')->willReturn(200);
            $response->method('getBody')->willReturn(json_encode(['results' => [], 'failed' => []], JSON_THROW_ON_ERROR));
            return $response;
        });
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $connection = $this->createMock(ConnectionConfigService::class);
        $service = new MediaLabClient($connection, $clientService, new RequestIdFactory(), new MediaLabErrorPolicy());
        $first = tempnam(sys_get_temp_dir(), 'nc-embed-a-');
        $second = tempnam(sys_get_temp_dir(), 'nc-embed-b-');
        self::assertIsString($first);
        self::assertIsString($second);
        file_put_contents($first, 'a');
        file_put_contents($second, 'b');

        try {
            $service->embedImageFiles([
                ['path' => $first, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'nextcloud_file_id' => 'secret-1'],
                ['path' => $second, 'mime_type' => 'image/png', 'extension' => 'png', 'original_filename' => 'real-name.png'],
            ], 120, ['base_url' => 'https://medialab.example/api/external/v1', 'token' => 'secret']);
        } finally {
            @unlink($first);
            @unlink($second);
        }

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://medialab.example/api/external/v1/embed/image/batch', $captured['url']);
        self::assertSame('images', $captured['options']['multipart'][0]['name']);
        self::assertSame('images', $captured['options']['multipart'][1]['name']);
        self::assertSame('image_0.jpg', $captured['options']['multipart'][0]['filename']);
        self::assertSame('image_1.png', $captured['options']['multipart'][1]['filename']);
        self::assertArrayNotHasKey('nextcloud_file_id', $captured['options']['multipart'][0]);
        self::assertArrayNotHasKey('original_filename', $captured['options']['multipart'][1]);
    }
}
