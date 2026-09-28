<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\IndexFreshness;
use PHPUnit\Framework\TestCase;

class IndexFreshnessTest extends TestCase
{
    private const INDEXED = ['etag' => 'e1', 'model_fingerprint' => 'fp', 'index_name' => 'idx'];

    public function testMatchingIndexEntryIsCurrent(): void
    {
        self::assertTrue(IndexFreshness::isCurrent(self::INDEXED, null, 'e1', 'fp', 'idx'));
    }

    public function testChangedEtagModelOrWriteIndexIsNotCurrent(): void
    {
        self::assertFalse(IndexFreshness::isCurrent(self::INDEXED, null, 'e2', 'fp', 'idx'));
        self::assertFalse(IndexFreshness::isCurrent(self::INDEXED, null, 'e1', 'fp2', 'idx'));
        self::assertFalse(IndexFreshness::isCurrent(self::INDEXED, null, 'e1', 'fp', 'idx2'));
        self::assertFalse(IndexFreshness::isCurrent(self::INDEXED, null, 'e1', 'fp', ''));
    }

    public function testMatchingSkipMarkerIsCurrent(): void
    {
        $skip = ['etag' => 'e1', 'model_fingerprint' => 'fp'];
        self::assertTrue(IndexFreshness::isCurrent(null, $skip, 'e1', 'fp', 'idx'));
        self::assertFalse(IndexFreshness::isCurrent(null, $skip, 'e2', 'fp', 'idx'));
        self::assertFalse(IndexFreshness::isCurrent(null, $skip, 'e1', 'other', 'idx'));
    }

    public function testMissingDataIsNeverCurrent(): void
    {
        self::assertFalse(IndexFreshness::isCurrent(null, null, 'e1', 'fp', 'idx'));
        self::assertFalse(IndexFreshness::isCurrent(self::INDEXED, null, '', 'fp', 'idx'));
        self::assertFalse(IndexFreshness::isCurrent(['etag' => 'e1', 'model_fingerprint' => ''], null, 'e1', '', 'idx'));
    }
}
