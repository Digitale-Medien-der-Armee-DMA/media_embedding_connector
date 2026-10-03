<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\FileStatusExportRepository;
use OCA\MediaEmbeddingConnector\Service\FileStatusExportService;
use PHPUnit\Framework\TestCase;

class FileStatusExportServiceTest extends TestCase
{
    public function testLargeExportIncludesEveryFileAndPreservesNamesAndReasons(): void
    {
        $repository = $this->createMock(FileStatusExportRepository::class);
        $repository->method('rows')->with('skipped')->willReturnCallback(static function (): \Generator {
            for ($i = 1; $i <= 23001; ++$i) {
                yield ['id' => $i, 'file_id' => $i + 100, 'file_name' => "Berg \"Mönch\".jpg", 'storage_path' => 'files/Berge/Mönch.jpg', 'reason' => 'image_too_large'];
            }
        });
        $stream = (new FileStatusExportService($repository))->create('skipped');
        try {
            $data = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue(array_is_list($data));
            self::assertCount(23001, $data);
            self::assertSame(23001, $data[23000]['id']);
            self::assertSame([
                'id' => 1, 'last_error' => 'image_too_large', 'storage_path' => 'files/Berge/Mönch.jpg',
            ], $data[0]);
        } finally { fclose($stream); }
    }

    public function testEmptyExportIsValidJson(): void
    {
        $repository = $this->createMock(FileStatusExportRepository::class);
        $repository->method('rows')->willReturnCallback(static function (): \Generator { yield from []; });
        $stream = (new FileStatusExportService($repository))->create('failed');
        try {
            $data = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $data);
        } finally { fclose($stream); }
    }

    public function testFailedDeleteKeepsItsJobIdAndMissingPathWithoutExportingQueueInternals(): void
    {
        $repository = $this->createMock(FileStatusExportRepository::class);
        $repository->method('rows')->with('failed')->willReturnCallback(static function (): \Generator {
            yield ['id' => '736', 'file_id' => 203677, 'owner_uid' => 'manuel', 'action' => 'delete',
                'last_error' => 'medialab_invalid_response', 'storage_path' => null, 'attempts' => 0];
        });
        $stream = (new FileStatusExportService($repository))->create('failed');
        try {
            self::assertSame([
                ['id' => 736, 'last_error' => 'medialab_invalid_response', 'storage_path' => null],
            ], json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR));
        } finally { fclose($stream); }
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileStatusExportService($this->createMock(FileStatusExportRepository::class)))->create('indexed');
    }
}
