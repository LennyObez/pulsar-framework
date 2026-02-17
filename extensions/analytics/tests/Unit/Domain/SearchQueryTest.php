<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\SearchQuery;

final class SearchQueryTest extends TestCase
{
    #[Test]
    public function construction_with_defaults(): void
    {
        $query = new SearchQuery(
            query: 'pulsar framework',
            count: 150,
        );

        self::assertSame('pulsar framework', $query->query);
        self::assertSame(150, $query->count);
        self::assertSame(0, $query->resultCount);
        self::assertSame(0.0, $query->clickThroughRate);
    }

    #[Test]
    public function construction_fully_specified(): void
    {
        $query = new SearchQuery(
            query: 'php framework',
            count: 500,
            resultCount: 25,
            clickThroughRate: 0.68,
        );

        self::assertSame('php framework', $query->query);
        self::assertSame(500, $query->count);
        self::assertSame(25, $query->resultCount);
        self::assertSame(0.68, $query->clickThroughRate);
    }

    #[Test]
    public function empty_query(): void
    {
        $query = new SearchQuery(query: '', count: 0);

        self::assertSame('', $query->query);
        self::assertSame(0, $query->count);
    }
}
