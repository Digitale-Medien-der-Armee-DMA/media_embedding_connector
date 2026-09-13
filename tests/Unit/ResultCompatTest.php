<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\ResultCompat;
use PHPUnit\Framework\TestCase;

final class ResultCompatTest extends TestCase
{
    public function testUsesTypedResultMethodsWhenAvailable(): void
    {
        $result = new class {
            public function fetchAssociative(): array
            {
                return ['id' => 1];
            }

            public function fetchAllAssociative(): array
            {
                return [['id' => 1], ['id' => 2]];
            }

            public function fetchFirstColumn(): array
            {
                return ['alice', 'bob'];
            }
        };

        self::assertSame(['id' => 1], ResultCompat::fetchAssociative($result));
        self::assertSame([['id' => 1], ['id' => 2]], ResultCompat::fetchAllAssociative($result));
        self::assertSame(['alice', 'bob'], ResultCompat::fetchFirstColumn($result));
    }

    public function testFallsBackToLegacyResultMethods(): void
    {
        $result = new class {
            public ?int $fetchAllMode = null;

            public function fetch(): array
            {
                return ['id' => 1];
            }

            public function fetchAll(?int $mode = null): array
            {
                $this->fetchAllMode = $mode;
                return $mode === \PDO::FETCH_COLUMN
                    ? ['alice', 'bob']
                    : [['id' => 1], ['id' => 2]];
            }
        };

        self::assertSame(['id' => 1], ResultCompat::fetchAssociative($result));
        self::assertSame([['id' => 1], ['id' => 2]], ResultCompat::fetchAllAssociative($result));
        self::assertSame(['alice', 'bob'], ResultCompat::fetchFirstColumn($result));
        self::assertSame(\PDO::FETCH_COLUMN, $result->fetchAllMode);
    }

    public function testNormalizesNonArraySingleRowsToFalse(): void
    {
        $result = new class {
            public function fetchAssociative(): false
            {
                return false;
            }
        };

        self::assertFalse(ResultCompat::fetchAssociative($result));
    }
}
