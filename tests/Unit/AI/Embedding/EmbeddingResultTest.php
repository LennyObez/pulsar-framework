<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Embedding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Embedding\EmbeddingVector;

#[CoversClass(EmbeddingResult::class)]
final class EmbeddingResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $v1 = new EmbeddingVector(values: [0.1, 0.2], index: 0);
        $result = new EmbeddingResult(
            embeddings: [$v1],
            totalTokens: 15,
            model: 'text-embedding-3-small',
        );

        self::assertCount(1, $result->embeddings);
        self::assertSame(15, $result->totalTokens);
        self::assertSame('text-embedding-3-small', $result->model);
    }

    #[Test]
    public function firstReturnsFirstEmbedding(): void
    {
        $v1 = new EmbeddingVector(values: [1.0, 2.0], index: 0);
        $v2 = new EmbeddingVector(values: [3.0, 4.0], index: 1);
        $result = new EmbeddingResult(embeddings: [$v1, $v2], totalTokens: 20, model: 'test');

        self::assertSame($v1, $result->first());
    }

    #[Test]
    public function firstReturnsNullForEmptyResult(): void
    {
        $result = new EmbeddingResult(embeddings: [], totalTokens: 0, model: 'test');

        self::assertNull($result->first());
    }

    #[Test]
    public function countReturnsNumberOfEmbeddings(): void
    {
        $v1 = new EmbeddingVector(values: [1.0], index: 0);
        $v2 = new EmbeddingVector(values: [2.0], index: 1);
        $v3 = new EmbeddingVector(values: [3.0], index: 2);
        $result = new EmbeddingResult(embeddings: [$v1, $v2, $v3], totalTokens: 30, model: 'test');

        self::assertSame(3, $result->count());
    }

    #[Test]
    public function countReturnsZeroForEmptyResult(): void
    {
        $result = new EmbeddingResult(embeddings: [], totalTokens: 0, model: 'test');

        self::assertSame(0, $result->count());
    }

    #[Test]
    public function singleEmbeddingBatch(): void
    {
        $vec = new EmbeddingVector(values: [0.5, -0.3, 0.8], index: 0);
        $result = new EmbeddingResult(embeddings: [$vec], totalTokens: 5, model: 'ada');

        self::assertSame(1, $result->count());
        self::assertSame($vec, $result->first());
        self::assertSame(5, $result->totalTokens);
    }
}
