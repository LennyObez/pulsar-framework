<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Embedding;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Embedding\EmbeddingInterface;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Embedding\EmbeddingVector;

use function count;

/**
 * Tests the EmbeddingInterface contract via anonymous implementation and stubs.
 */
final class EmbeddingInterfaceTest extends TestCase
{
    #[Test]
    public function implementationGeneratesEmbeddings(): void
    {
        // Arrange
        $impl = $this->createFakeEmbedding(dimensions: 3, model: 'test-embed-v1');

        // Act
        $result = $impl->embed(['Hello world']);

        // Assert
        self::assertSame(1, $result->count());
        self::assertNotNull($result->first());
        self::assertCount(3, $result->first()->values);
    }

    #[Test]
    public function implementationHandlesMultipleInputs(): void
    {
        // Arrange
        $impl = $this->createFakeEmbedding(dimensions: 4, model: 'multi-embed');

        // Act
        $result = $impl->embed(['text one', 'text two', 'text three']);

        // Assert
        self::assertSame(3, $result->count());
        self::assertSame(0, $result->embeddings[0]->index);
        self::assertSame(1, $result->embeddings[1]->index);
        self::assertSame(2, $result->embeddings[2]->index);
    }

    #[Test]
    public function dimensionsReturnsVectorSize(): void
    {
        // Arrange
        $impl = $this->createFakeEmbedding(dimensions: 1536, model: 'ada-002');

        // Act & Assert
        self::assertSame(1536, $impl->dimensions());
    }

    #[Test]
    public function modelReturnsModelIdentifier(): void
    {
        // Arrange
        $impl = $this->createFakeEmbedding(dimensions: 768, model: 'all-MiniLM-L6-v2');

        // Act & Assert
        self::assertSame('all-MiniLM-L6-v2', $impl->model());
    }

    #[Test]
    public function embedReturnsEmptyResultForEmptyInput(): void
    {
        // Arrange
        $impl = $this->createFakeEmbedding(dimensions: 3, model: 'test');

        // Act
        $result = $impl->embed([]);

        // Assert
        self::assertSame(0, $result->count());
        self::assertNull($result->first());
    }

    #[Test]
    public function stubSatisfiesInterfaceContract(): void
    {
        // Arrange
        $vector = new EmbeddingVector([0.1, 0.2, 0.3], 0);
        $embeddingResult = new EmbeddingResult([$vector], 10, 'stub-model');

        $stub = $this->createStub(EmbeddingInterface::class);
        $stub->method('embed')->willReturn($embeddingResult);
        $stub->method('dimensions')->willReturn(3);
        $stub->method('model')->willReturn('stub-model');

        // Act & Assert
        self::assertSame(3, $stub->dimensions());
        self::assertSame('stub-model', $stub->model());

        $result = $stub->embed(['test']);
        self::assertSame(1, $result->count());
        self::assertSame(10, $result->totalTokens);
    }

    private function createFakeEmbedding(int $dimensions, string $model): EmbeddingInterface
    {
        return new class ($dimensions, $model) implements EmbeddingInterface {
            public function __construct(
                private readonly int $dims,
                private readonly string $modelName,
            ) {}

            public function embed(array $inputs): EmbeddingResult
            {
                $embeddings = [];

                foreach ($inputs as $index => $input) {
                    $values = array_fill(0, $this->dims, 0.1);
                    $embeddings[] = new EmbeddingVector($values, $index);
                }

                return new EmbeddingResult($embeddings, count($inputs) * 5, $this->modelName);
            }

            public function dimensions(): int
            {
                return $this->dims;
            }

            public function model(): string
            {
                return $this->modelName;
            }
        };
    }
}
