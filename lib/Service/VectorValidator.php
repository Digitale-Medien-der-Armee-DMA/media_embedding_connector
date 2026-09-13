<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

class VectorValidator
{
    /**
     * @param mixed $vector
     * @return list<string>
     */
    public function validate(mixed $vector, int $embeddingDim, bool $normalized): array
    {
        $errors = [];
        if (!is_array($vector)) {
            return ['vector must be a list.'];
        }

        if (count($vector) !== $embeddingDim) {
            $errors[] = 'vector dimension mismatch: expected ' . $embeddingDim . ', got ' . count($vector) . '.';
        }

        $sumSquares = 0.0;
        foreach ($vector as $index => $value) {
            if (!is_int($value) && !is_float($value)) {
                $errors[] = 'vector[' . $index . '] must be numeric.';
                continue;
            }

            $floatValue = (float)$value;
            if (is_nan($floatValue)) {
                $errors[] = 'vector[' . $index . '] must not be NaN.';
                continue;
            }
            if (is_infinite($floatValue)) {
                $errors[] = 'vector[' . $index . '] must not be infinite.';
                continue;
            }

            $sumSquares += $floatValue * $floatValue;
        }

        if ($normalized && $errors === []) {
            $norm = sqrt($sumSquares);
            if (abs($norm - 1.0) > 0.01) {
                $errors[] = 'normalized vector norm must be approximately 1.0; got ' . (string)round($norm, 6) . '.';
            }
        }

        return $errors;
    }

    /**
     * Convert a vector after it has passed {@see validate()}.
     *
     * @param array<mixed> $vector
     * @return list<float>
     */
    public function normalizeValidated(array $vector): array
    {
        return array_values(array_map(static fn (mixed $value): float => (float)$value, $vector));
    }

    /**
     * @param array<mixed> $vector
     * @return array<string, mixed>
     */
    public function summarize(array $vector): array
    {
        $sumSquares = 0.0;
        foreach ($vector as $value) {
            if (is_int($value) || is_float($value)) {
                $sumSquares += ((float)$value) * ((float)$value);
            }
        }

        return [
            'dimension' => count($vector),
            'norm' => round(sqrt($sumSquares), 6),
        ];
    }
}
