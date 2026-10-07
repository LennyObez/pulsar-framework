<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ZeroTrust\Signal\BehaviorBaseline;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;

#[CoversClass(GeoLocation::class)]
#[CoversClass(BehaviorBaseline::class)]
final class GeoLocationTest extends TestCase
{
    // ── GeoLocation ───────────────────────────────────────────────────

    #[Test]
    public function constructionStoresCoordinates(): void
    {
        $loc = new GeoLocation(
            latitude: 48.8566,
            longitude: 2.3522,
            country: 'FR',
            city: 'Paris',
        );

        self::assertSame(48.8566, $loc->latitude);
        self::assertSame(2.3522, $loc->longitude);
        self::assertSame('FR', $loc->country);
        self::assertSame('Paris', $loc->city);
    }

    #[Test]
    public function defaultCityIsEmpty(): void
    {
        $loc = new GeoLocation(latitude: 0.0, longitude: 0.0, country: 'XX');

        self::assertSame('', $loc->city);
    }

    #[Test]
    public function distanceToSameLocationIsZero(): void
    {
        $loc = new GeoLocation(latitude: 40.7128, longitude: -74.006, country: 'US', city: 'New York');

        self::assertEqualsWithDelta(0.0, $loc->distanceTo($loc), 0.01);
    }

    #[Test]
    public function distanceNewYorkToLondon(): void
    {
        $nyc = new GeoLocation(latitude: 40.7128, longitude: -74.006, country: 'US', city: 'New York');
        $london = new GeoLocation(latitude: 51.5074, longitude: -0.1278, country: 'GB', city: 'London');

        $distance = $nyc->distanceTo($london);

        // NYC to London is approximately 5570 km
        self::assertGreaterThan(5500.0, $distance);
        self::assertLessThan(5700.0, $distance);
    }

    #[Test]
    public function distanceIsSymmetric(): void
    {
        $a = new GeoLocation(latitude: 35.6762, longitude: 139.6503, country: 'JP', city: 'Tokyo');
        $b = new GeoLocation(latitude: -33.8688, longitude: 151.2093, country: 'AU', city: 'Sydney');

        self::assertEqualsWithDelta($a->distanceTo($b), $b->distanceTo($a), 0.01);
    }

    #[Test]
    public function distanceAtEquator(): void
    {
        // Two points 1 degree apart at the equator should be ~111 km
        $a = new GeoLocation(latitude: 0.0, longitude: 0.0, country: 'XX');
        $b = new GeoLocation(latitude: 0.0, longitude: 1.0, country: 'XX');

        $distance = $a->distanceTo($b);

        self::assertGreaterThan(110.0, $distance);
        self::assertLessThan(112.0, $distance);
    }

    // ── BehaviorBaseline ──────────────────────────────────────────────

    #[Test]
    public function behaviorBaselineStoresProperties(): void
    {
        $now = new DateTimeImmutable();
        $baseline = new BehaviorBaseline(
            avgRequestsPerMinute: 12.5,
            knownPatterns: ['endpoints' => ['/api/v1/users', '/api/v1/orders']],
            lastActivity: $now,
        );

        self::assertSame(12.5, $baseline->avgRequestsPerMinute);
        self::assertCount(1, $baseline->knownPatterns);
        self::assertSame($now, $baseline->lastActivity);
    }

    #[Test]
    public function behaviorBaselineDefaultsToEmptyPatternsAndNullActivity(): void
    {
        $baseline = new BehaviorBaseline(avgRequestsPerMinute: 0.0);

        self::assertSame(0.0, $baseline->avgRequestsPerMinute);
        self::assertSame([], $baseline->knownPatterns);
        self::assertNull($baseline->lastActivity);
    }
}
