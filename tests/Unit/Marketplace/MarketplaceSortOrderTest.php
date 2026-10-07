<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Marketplace\MarketplaceSortOrder;

#[CoversNothing]
final class MarketplaceSortOrderTest extends TestCase
{
    #[Test]
    #[DataProvider('sortOrderProvider')]
    public function caseHasCorrectStringValue(MarketplaceSortOrder $order, string $expected): void
    {
        self::assertSame($expected, $order->value);
    }

    /**
     * @return iterable<string, array{MarketplaceSortOrder, string}>
     */
    public static function sortOrderProvider(): iterable
    {
        yield 'Relevance' => [MarketplaceSortOrder::Relevance, 'relevance'];
        yield 'Downloads' => [MarketplaceSortOrder::Downloads, 'downloads'];
        yield 'Rating' => [MarketplaceSortOrder::Rating, 'rating'];
        yield 'Newest' => [MarketplaceSortOrder::Newest, 'newest'];
        yield 'Name' => [MarketplaceSortOrder::Name, 'name'];
    }

    #[Test]
    public function fromReturnsValidCase(): void
    {
        self::assertSame(MarketplaceSortOrder::Downloads, MarketplaceSortOrder::from('downloads'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(MarketplaceSortOrder::tryFrom('invalid'));
    }

    #[Test]
    public function allCasesAreMapped(): void
    {
        self::assertCount(5, MarketplaceSortOrder::cases());
    }
}
