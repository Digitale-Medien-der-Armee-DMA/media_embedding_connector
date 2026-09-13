<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Service\VectorValidator;
use PHPUnit\Framework\TestCase;

class VectorValidatorTest extends TestCase
{
    public function testNormalizedVectorIsAccepted(): void
    {
        self::assertSame([], (new VectorValidator())->validate([0.6, 0.8], 2, true));
    }

    public function testDimensionMismatchIsRejected(): void
    {
        self::assertNotSame([], (new VectorValidator())->validate([1.0], 2, false));
    }

    public function testValidatedVectorIsNormalizedToFloatList(): void
    {
        self::assertSame([1.0, 2.5], (new VectorValidator())->normalizeValidated([2 => 1, 7 => 2.5]));
    }
}
