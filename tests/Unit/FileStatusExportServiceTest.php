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
                yield ['file_id' => $i, 'file_name' => "Berg \"Mönch\".jpg", 'storage_path' => 'files/Berge/photo.jpg', 'reason' => 'image_too_large'];
            }
        });
        $stream = (new FileStatusExportService($repository))->create('skipped');
        try {
            $data = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(23001, $data['count']);
            self::assertCount(23001, $data['files']);
            self::assertSame(23001, $data['files'][23000]['file_id']);
            self::assertSame("Berg \"Mönch\".jpg", $data['files'][0]['file_name']);
            self::assertSame('image_too_large', $data['files'][0]['reason']);
        } finally { fclose($stream); }
    }

    public function testEmptyExportIsValidJson(): void
    {
        $repository = $this->createMock(FileStatusExportRepository::class);
        $repository->method('rows')->willReturnCallback(static function (): \Generator { yield from []; });
        $stream = (new FileStatusExportService($repository))->create('failed');
        try {
            $data = json_decode(stream_get_contents($stream), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $data['files']);
            self::assertSame(0, $data['count']);
        } finally { fclose($stream); }
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileStatusExportService($this->createMock(FileStatusExportRepository::class)))->create('indexed');
    }
}
