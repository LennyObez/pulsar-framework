<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;

#[CoversClass(SegmentDimension::class)]
final class SegmentDimensionTest extends TestCase
{
    #[Test]
    public function hasTwelveCases(): void
    {
        self::assertCount(12, SegmentDimension::cases());
    }

    #[Test]
    #[DataProvider('dimensionValuesProvider')]
    public function fromStringReturnsCorrectCase(string $value, SegmentDimension $expected): void
    {
        self::assertSame($expected, SegmentDimension::from($value));
    }

    /** @return iterable<string, array{string, SegmentDimension}> */
    public static function dimensionValuesProvider(): iterable
    {
        yield 'country' => ['country', SegmentDimension::Country];
        yield 'browser' => ['browser', SegmentDimension::Browser];
        yield 'os' => ['os', SegmentDimension::Os];
        yield 'device_type' => ['device_type', SegmentDimension::DeviceType];
        yield 'referrer_source' => ['referrer_source', SegmentDimension::ReferrerSource];
        yield 'entry_page' => ['entry_page', SegmentDimension::EntryPage];
        yield 'event_name' => ['event_name', SegmentDimension::EventName];
        yield 'page_visited' => ['page_visited', SegmentDimension::PageVisited];
        yield 'visit_count' => ['visit_count', SegmentDimension::VisitCount];
        yield 'utm_source' => ['utm_source', SegmentDimension::UtmSource];
        yield 'utm_medium' => ['utm_medium', SegmentDimension::UtmMedium];
        yield 'utm_campaign' => ['utm_campaign', SegmentDimension::UtmCampaign];
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(SegmentDimension::tryFrom('nonexistent'));
    }
}
