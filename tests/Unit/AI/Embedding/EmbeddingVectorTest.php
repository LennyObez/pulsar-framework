<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Embedding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Embedding\EmbeddingVector;

#[CoversClass(EmbeddingVector::class)]
#[CoversClass(EmbeddingResult::class)]
final class EmbeddingVectorTest extends TestCase
{
    #[Test]
    public function dimensionsReturnsVectorLength(): void
    {
        $vec = new EmbeddingVector(values: [1.0, 2.0, 3.0], index: 0);

        self::assertSame(3, $vec->dimensions());
    }

    #[Test]
    public function normComputesL2Norm(): void
    {
        // 3-4-5 triangle: sqrt(9 + 16) = 5
        $vec = new EmbeddingVector(values: [3.0, 4.0], index: 0);

        self::assertEqualsWithDelta(5.0, $vec->norm(), 0.0001);
    }

    #[Test]
    public function normOfZeroVectorIsZero(): void
    {
        $vec = new EmbeddingVector(values: [0.0, 0.0, 0.0], index: 0);

        self::assertSame(0.0, $vec->norm());
    }

    #[Test]
    public function cosineSimilarityOfIdenticalVectorsIsOne(): void
    {
        $a = new EmbeddingVector(values: [1.0, 2.0, 3.0], index: 0);
        $b = new EmbeddingVector(values: [1.0, 2.0, 3.0], index: 1);

        self::assertEqualsWithDelta(1.0, $a->cosineSimilarity($b), 0.0001);
    }

    #[Test]
    public function cosineSimilarityOfOrthogonalVectorsIsZero(): void
    {
        $a = new EmbeddingVector(values: [1.0, 0.0], index: 0);
        $b = new EmbeddingVector(values: [0.0, 1.0], index: 1);

        self::assertEqualsWithDelta(0.0, $a->cosineSimilarity($b), 0.0001);
    }

    #[Test]
    public function cosineSimilarityOfOppositeVectorsIsNegativeOne(): void
    {
        $a = new EmbeddingVector(values: [1.0, 0.0], index: 0);
        $b = new EmbeddingVector(values: [-1.0, 0.0], index: 1);

        self::assertEqualsWithDelta(-1.0, $a->cosineSimilarity($b), 0.0001);
    }

    #[Test]
    public function cosineSimilarityHandlesZeroVector(): void
    {
        $a = new EmbeddingVector(values: [0.0, 0.0], index: 0);
        $b = new EmbeddingVector(values: [1.0, 2.0], index: 1);

        self::assertSame(0.0, $a->cosineSimilarity($b));
    }

    #[Test]
    public function embeddingResultFirstReturnsFirstVector(): void
    {
        $v1 = new EmbeddingVector(values: [1.0], index: 0);
        $v2 = new EmbeddingVector(values: [2.0], index: 1);

        $result = new EmbeddingResult(embeddings: [$v1, $v2], totalTokens: 10, model: 'test');

        self::assertSame($v1, $result->first());
    }

    #[Test]
    public function embeddingResultFirstReturnsNullWhenEmpty(): void
    {
        $result = new EmbeddingResult(embeddings: [], totalTokens: 0, model: 'test');

        self::assertNull($result->first());
    }

    #[Test]
    public function embeddingResultCountReturnsEmbeddingCount(): void
    {
        $v1 = new EmbeddingVector(values: [1.0], index: 0);
        $v2 = new EmbeddingVector(values: [2.0], index: 1);

        $result = new EmbeddingResult(embeddings: [$v1, $v2], totalTokens: 5, model: 'test');

        self::assertSame(2, $result->count());
        self::assertSame(5, $result->totalTokens);
        self::assertSame('test', $result->model);
    }
}
