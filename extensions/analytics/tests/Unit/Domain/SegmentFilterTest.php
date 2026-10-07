<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;

#[CoversClass(SegmentFilter::class)]
final class SegmentFilterTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $filter = new SegmentFilter(
            dimension: SegmentDimension::Country,
            operator: SegmentOperator::Equals,
            value: 'DE',
        );

        self::assertSame(SegmentDimension::Country, $filter->dimension);
        self::assertSame(SegmentOperator::Equals, $filter->operator);
        self::assertSame('DE', $filter->value);
    }

    #[Test]
    public function containsOperatorFilter(): void
    {
        $filter = new SegmentFilter(
            dimension: SegmentDimension::PageVisited,
            operator: SegmentOperator::Contains,
            value: '/blog/',
        );

        self::assertSame(SegmentDimension::PageVisited, $filter->dimension);
        self::assertSame(SegmentOperator::Contains, $filter->operator);
    }
}
