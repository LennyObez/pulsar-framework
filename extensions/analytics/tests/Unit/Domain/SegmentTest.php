<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\Segment;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;

#[CoversClass(Segment::class)]
final class SegmentTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-01');
        $filters = [
            new SegmentFilter(SegmentDimension::Country, SegmentOperator::Equals, 'US'),
            new SegmentFilter(SegmentDimension::Browser, SegmentOperator::Contains, 'Chrome'),
        ];

        $segment = new Segment(
            id: 'seg-1',
            siteId: 'site-1',
            name: 'US Chrome Users',
            filters: $filters,
            createdAt: $now,
        );

        self::assertSame('seg-1', $segment->id);
        self::assertSame('site-1', $segment->siteId);
        self::assertSame('US Chrome Users', $segment->name);
        self::assertCount(2, $segment->filters);
        self::assertSame($now, $segment->createdAt);
    }

    #[Test]
    public function createdAtDefaultsToNow(): void
    {
        $segment = new Segment('seg-2', 'site-1', 'Test', []);

        self::assertInstanceOf(DateTimeImmutable::class, $segment->createdAt);
    }

    #[Test]
    public function emptyFiltersAllowed(): void
    {
        $segment = new Segment('seg-3', 'site-1', 'All Visitors', []);

        self::assertSame([], $segment->filters);
    }
}
