<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Marketplace\MarketplaceSearchFilter;
use Pulsar\Marketplace\MarketplaceSortOrder;

#[CoversClass(MarketplaceSearchFilter::class)]
final class MarketplaceSearchFilterTest extends TestCase
{
    #[Test]
    public function defaultsAreApplied(): void
    {
        $filter = new MarketplaceSearchFilter();

        self::assertNull($filter->trustTier);
        self::assertNull($filter->category);
        self::assertNull($filter->pulsarVersion);
        self::assertSame(MarketplaceSortOrder::Relevance, $filter->sortBy);
        self::assertSame(50, $filter->limit);
        self::assertSame(0, $filter->offset);
    }

    #[Test]
    public function customValuesAreStored(): void
    {
        $filter = new MarketplaceSearchFilter(
            trustTier: TrustTier::Verified,
            category: 'security',
            pulsarVersion: '1.0.0',
            sortBy: MarketplaceSortOrder::Downloads,
            limit: 25,
            offset: 50,
        );

        self::assertSame(TrustTier::Verified, $filter->trustTier);
        self::assertSame('security', $filter->category);
        self::assertSame('1.0.0', $filter->pulsarVersion);
        self::assertSame(MarketplaceSortOrder::Downloads, $filter->sortBy);
        self::assertSame(25, $filter->limit);
        self::assertSame(50, $filter->offset);
    }
}
