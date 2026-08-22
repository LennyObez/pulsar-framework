<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\VectorStore\SearchResult;
use Pulsar\AI\VectorStore\VectorStoreInterface;

/**
 * Tests the VectorStoreInterface contract via stubs.
 */
final class VectorStoreInterfaceTest extends TestCase
{
    #[Test]
    public function searchReturnsOrderedResults(): void
    {
        // Arrange
        $results = [
            new SearchResult('doc-1', 0.95, 'First result', ['category' => 'a']),
            new SearchResult('doc-2', 0.87, 'Second result', ['category' => 'b']),
        ];

        $stub = $this->createStub(VectorStoreInterface::class);
        $stub->method('search')->willReturn($results);

        // Act
        $found = $stub->search([0.1, 0.2, 0.3], 10);

        // Assert
        self::assertCount(2, $found);
        self::assertSame('doc-1', $found[0]->id);
        self::assertGreaterThan($found[1]->score, $found[0]->score);
    }

    #[Test]
    public function searchReturnsEmptyArrayWhenNoMatches(): void
    {
        // Arrange
        $stub = $this->createStub(VectorStoreInterface::class);
        $stub->method('search')->willReturn([]);

        // Act
        $results = $stub->search([0.5, 0.5], 5);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function searchAcceptsMetadataFilter(): void
    {
        // Arrange
        $results = [new SearchResult('doc-filtered', 0.9, 'Filtered', ['type' => 'article'])];
        $stub = $this->createStub(VectorStoreInterface::class);
        $stub->method('search')->willReturn($results);

        // Act
        $found = $stub->search([0.1], 5, ['type' => 'article']);

        // Assert
        self::assertCount(1, $found);
        self::assertSame('article', $found[0]->metadata['type']);
    }

    #[Test]
    public function countReturnsDocumentCount(): void
    {
        // Arrange
        $stub = $this->createStub(VectorStoreInterface::class);
        $stub->method('count')->willReturn(42);

        // Act & Assert
        self::assertSame(42, $stub->count());
    }

    #[Test]
    public function countWithFilterReturnsFilteredCount(): void
    {
        // Arrange
        $stub = $this->createStub(VectorStoreInterface::class);
        $stub->method('count')->willReturn(7);

        // Act & Assert
        self::assertSame(7, $stub->count(['category' => 'docs']));
    }

    #[Test]
    public function countReturnsZeroForEmptyStore(): void
    {
        // Arrange
        $stub = $this->createStub(VectorStoreInterface::class);
        $stub->method('count')->willReturn(0);

        // Act & Assert
        self::assertSame(0, $stub->count());
    }
}
