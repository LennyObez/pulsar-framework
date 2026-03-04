<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ThreatDetection\GeoAnomalyDetector;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatResponse;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

#[CoversClass(GeoAnomalyDetector::class)]
final class GeoAnomalyDetectorTest extends TestCase
{
    public function testAnalyzeAlwaysReturnsNull(): void
    {
        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $detector = new GeoAnomalyDetector($resolver, 900);
        $request = $this->createStub(ServerRequestInterface::class);

        self::assertNull($detector->analyze($request));
    }

    public function testCheckLoginReturnsNullWhenLocationUnresolvable(): void
    {
        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $detector = new GeoAnomalyDetector($resolver, 900);
        self::assertNull($detector->checkLogin('user1', '10.0.0.1'));
    }

    public function testCheckLoginReturnsNullOnFirstLogin(): void
    {
        $location = new GeoLocation(48.8566, 2.3522, 'FR', 'Paris');

        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn($location);

        $detector = new GeoAnomalyDetector($resolver, 900);
        self::assertNull($detector->checkLogin('user1', '10.0.0.1'));
    }

    public function testCheckLoginReturnsNullForSameLocation(): void
    {
        // Same location → distance 0 → no impossible travel
        $paris = new GeoLocation(48.8566, 2.3522, 'FR', 'Paris');

        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn($paris);

        $detector = new GeoAnomalyDetector($resolver, 900);

        $detector->checkLogin('user1', '1.1.1.1');
        $result = $detector->checkLogin('user1', '2.2.2.2');

        // Same location both times — required speed is 0, which is <= 900
        self::assertNull($result);
    }

    public function testCheckLoginDetectsImpossibleTravel(): void
    {
        // Paris to Tokyo (~9700 km) — impossible if both logins happen in quick succession
        $paris = new GeoLocation(48.8566, 2.3522, 'FR', 'Paris');
        $tokyo = new GeoLocation(35.6762, 139.6503, 'JP', 'Tokyo');

        $callCount = 0;
        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function () use (&$callCount, $paris, $tokyo): GeoLocation {
            return $callCount++ === 0 ? $paris : $tokyo;
        });

        $detector = new GeoAnomalyDetector($resolver, 900);

        // First login from Paris
        $detector->checkLogin('user1', '1.1.1.1');

        // Immediate second login from Tokyo — impossible
        $event = $detector->checkLogin('user1', '2.2.2.2');

        self::assertNotNull($event);
        self::assertSame(ThreatCategory::GeoAnomaly, $event->category);
        self::assertSame(ThreatResponse::Challenge, $event->recommendedAction);
        self::assertArrayHasKey('distance_km', $event->metadata);
        self::assertArrayHasKey('user_id', $event->metadata);
    }

    public function testRecordEventIsNoOp(): void
    {
        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $detector = new GeoAnomalyDetector($resolver, 900);

        // Should not throw
        $detector->recordEvent('auth.success', ['ip' => '10.0.0.1']);
        self::assertNull($detector->analyze($this->createStub(ServerRequestInterface::class)));
    }
}
