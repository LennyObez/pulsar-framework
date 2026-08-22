<?php

declare(strict_types=1);

namespace Pulsar\AI\Embedding;

use NoDiscard;
use Pulsar\Api\Api;

use function count;
use function sqrt;

/**
 * A single embedding vector with its source index.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EmbeddingVector
{
    /**
     * @param list<float> $values The dense vector values
     * @param int $index Position in the original input batch
     */
    public function __construct(
        public array $values,
        public int $index,
    ) {}

    /**
     * Number of dimensions in this vector.
     */
    public function dimensions(): int
    {
        return count($this->values);
    }

    /**
     * Compute the L2 (Euclidean) norm of this vector.
     */
    #[NoDiscard]
    public function norm(): float
    {
        $sum = 0.0;

        foreach ($this->values as $v) {
            $sum += $v * $v;
        }

        return sqrt($sum);
    }

    /**
     * Compute cosine similarity with another vector.
     *
     * Returns a value between -1.0 and 1.0, where 1.0 means identical direction.
     */
    #[NoDiscard]
    public function cosineSimilarity(self $other): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($this->values as $i => $a) {
            $b = $other->values[$i] ?? 0.0;
            $dot += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $dot / $denominator;
    }
}
