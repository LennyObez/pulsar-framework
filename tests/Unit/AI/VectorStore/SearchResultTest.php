<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\VectorStore\DistanceMetric;
use Pulsar\AI\VectorStore\SearchResult;

#[CoversClass(SearchResult::class)]
final class SearchResultTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $result = new SearchResult(
            id: 'doc-123',
            score: 0.95,
            content: 'Test document content',
            metadata: ['source' => 'wiki', 'page' => 42],
        );

        self::assertSame('doc-123', $result->id);
        self::assertSame(0.95, $result->score);
        self::assertSame('Test document content', $result->content);
        self::assertSame('wiki', $result->metadata['source']);
        self::assertSame(42, $result->metadata['page']);
    }

    #[Test]
    public function metadataDefaultsToEmpty(): void
    {
        $result = new SearchResult(id: 'a', score: 1.0, content: 'text');

        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function distanceMetricEnumValues(): void
    {
        self::assertSame('cosine', DistanceMetric::Cosine->value);
        self::assertSame('l2', DistanceMetric::L2->value);
        self::assertSame('inner_product', DistanceMetric::InnerProduct->value);
    }

    #[Test]
    public function distanceMetricHasThreeValues(): void
    {
        $cases = DistanceMetric::cases();

        self::assertCount(3, $cases);
    }
}
