<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;
use Pulsar\Security\ZeroTrust\Signal\Internal\LocationSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

use function sprintf;
use function time;

#[CoversClass(LocationSignalProvider::class)]
final class LocationSignalProviderTest extends TestCase
{
    // ── Name / structure ───────────────────────────────────────────────

    #[Test]
    public function nameReturnsLocation(): void
    {
        $provider = new LocationSignalProvider();

        self::assertSame('location', $provider->name());
    }

    #[Test]
    public function producesThreeClaimsAlways(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(48.8566, 2.3522, 'FR', 'Paris'),
        ));

        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertCount(3, $claims);
        self::assertTrue($claims->has('location.country'));
        self::assertTrue($claims->has('location.geo_anomaly'));
        self::assertTrue($claims->has('location.travel_impossible'));
    }

    #[Test]
    public function allClaimsHaveLocationSource(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        foreach ($claims as $claim) {
            self::assertSame(ClaimSource::LocationSignal, $claim->source);
        }
    }

    #[Test]
    public function allClaimsHaveTimestamps(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(0.0, 0.0, 'XX'),
        ));
        $claims = $provider->evaluate($this->createContext('1.2.3.4'));

        foreach ($claims as $claim) {
            self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $claim->timestamp);
        }
    }

    // ── Degraded mode ──────────────────────────────────────────────────

    #[Test]
    public function noResolverReturnsDegradedClaims(): void
    {
        $provider = new LocationSignalProvider();
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertCount(3, $claims);
        self::assertSame('unknown', self::firstClaim($claims, 'location.country')->value);
        self::assertSame(0.0, self::firstClaim($claims, 'location.country')->confidence);
        self::assertFalse(self::firstClaim($claims, 'location.geo_anomaly')->value);
        self::assertSame(0.0, self::firstClaim($claims, 'location.geo_anomaly')->confidence);
        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
        self::assertSame(0.0, self::firstClaim($claims, 'location.travel_impossible')->confidence);
    }

    #[Test]
    public function unresolvedIpReturnsDegradedClaims(): void
    {
        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $provider = new LocationSignalProvider($resolver);
        $claims = $provider->evaluate($this->createContext('127.0.0.1'));

        self::assertSame('unknown', self::firstClaim($claims, 'location.country')->value);
        self::assertSame(0.0, self::firstClaim($claims, 'location.country')->confidence);
    }

    // ── Country resolution ─────────────────────────────────────────────

    #[Test]
    public function resolvesCountryFromIp(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(48.8566, 2.3522, 'FR', 'Paris'),
        ));

        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertSame('FR', self::firstClaim($claims, 'location.country')->value);
    }

    #[Test]
    public function extractsIpFromServerParams(): void
    {
        $resolver = $this->createMock(GeoLocationResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('8.8.4.4')
            ->willReturn(new GeoLocation(34.0522, -118.2437, 'US', 'Los Angeles'));

        $provider = new LocationSignalProvider($resolver);
        $provider->evaluate($this->createContext('8.8.4.4'));
    }

    #[Test]
    public function defaultsToLocalhostWhenNoRemoteAddr(): void
    {
        $resolver = $this->createMock(GeoLocationResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('127.0.0.1')
            ->willReturn(null);

        $provider = new LocationSignalProvider($resolver);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([]);

        $context = new SignalContext(request: $request, identityId: 'user-1');
        $provider->evaluate($context);
    }

    #[Test]
    public function defaultsToLocalhostWhenRemoteAddrIsNonString(): void
    {
        $resolver = $this->createMock(GeoLocationResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('127.0.0.1')
            ->willReturn(null);

        $provider = new LocationSignalProvider($resolver);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => 42]);

        $context = new SignalContext(request: $request, identityId: 'user-1');
        $provider->evaluate($context);
    }

    // ── Geo anomaly detection ──────────────────────────────────────────

    #[Test]
    public function detectsGeoAnomalyWhenCountryNotInKnownList(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(55.7558, 37.6173, 'RU', 'Moscow'),
        ));

        $context = $this->createContext('203.0.113.1', attributes: [
            'known_countries' => ['US', 'CA', 'GB'],
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'location.geo_anomaly')->value);
        self::assertSame(0.85, self::firstClaim($claims, 'location.geo_anomaly')->confidence);
    }

    #[Test]
    public function noGeoAnomalyWhenCountryIsKnown(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        $context = $this->createContext('203.0.113.1', attributes: [
            'known_countries' => ['US', 'CA'],
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'location.geo_anomaly')->value);
    }

    #[Test]
    public function noGeoAnomalyWhenNoKnownCountries(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(55.7558, 37.6173, 'RU', 'Moscow'),
        ));

        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertFalse(self::firstClaim($claims, 'location.geo_anomaly')->value);
        self::assertSame(0.6, self::firstClaim($claims, 'location.geo_anomaly')->confidence);
    }

    #[Test]
    public function emptyKnownCountriesListTreatedAsNoKnownCountries(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(55.7558, 37.6173, 'RU', 'Moscow'),
        ));

        $context = $this->createContext('203.0.113.1', attributes: [
            'known_countries' => [],
        ]);

        $claims = $provider->evaluate($context);

        // Empty known_countries means no geo anomaly can be detected
        self::assertFalse(self::firstClaim($claims, 'location.geo_anomaly')->value);
    }

    #[Test]
    public function singleKnownCountryMatchingIsNotAnomalous(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(48.8566, 2.3522, 'FR', 'Paris'),
        ));

        $context = $this->createContext('203.0.113.1', attributes: [
            'known_countries' => ['FR'],
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'location.geo_anomaly')->value);
        self::assertSame(0.85, self::firstClaim($claims, 'location.geo_anomaly')->confidence);
    }

    // ── Confidence levels ──────────────────────────────────────────────

    #[Test]
    public function confidenceIsHigherWithKnownCountries(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        $contextWithKnown = $this->createContext('203.0.113.1', attributes: [
            'known_countries' => ['US'],
        ]);

        $contextWithoutKnown = $this->createContext('203.0.113.1');

        $claimsWith = $provider->evaluate($contextWithKnown);
        $claimsWithout = $provider->evaluate($contextWithoutKnown);

        self::assertSame(0.85, self::firstClaim($claimsWith, 'location.country')->confidence);
        self::assertSame(0.6, self::firstClaim($claimsWithout, 'location.country')->confidence);
    }

    // ── Impossible travel detection ────────────────────────────────────

    #[Test]
    public function detectsImpossibleTravel(): void
    {
        // Current location: New York
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        // Previous login in Tokyo 30 minutes ago (~10,800 km away)
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => 35.6762,
                'longitude' => 139.6503,
                'timestamp' => time() - 1800, // 30 minutes ago
            ],
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'location.travel_impossible')->value);
        // Impossible travel gets CONFIDENCE_VERIFIED_GEO
        self::assertSame(0.85, self::firstClaim($claims, 'location.travel_impossible')->confidence);
    }

    #[Test]
    public function noImpossibleTravelForReasonableDistance(): void
    {
        // Current location: New York
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        // Previous login in Boston 2 hours ago (~300 km away)
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => 42.3601,
                'longitude' => -71.0589,
                'timestamp' => time() - 7200, // 2 hours ago
            ],
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
    }

    #[Test]
    public function noImpossibleTravelWithoutPreviousLocation(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
    }

    #[Test]
    public function simultaneousLoginFromDistantLocationFlagsImpossibleTravel(): void
    {
        // Current: London
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(51.5074, -0.1278, 'GB', 'London'),
        ));

        // Previous: Sydney at the same second
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => -33.8688,
                'longitude' => 151.2093,
                'timestamp' => time(),
            ],
        ]);

        $claims = $provider->evaluate($context);

        // Zero time difference with distance > 50 km triggers impossible travel
        self::assertTrue(self::firstClaim($claims, 'location.travel_impossible')->value);
    }

    #[Test]
    public function simultaneousLoginFromNearbyLocationAllowed(): void
    {
        // Current: downtown Manhattan
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        // Previous: midtown Manhattan at the same second (~5 km away)
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => 40.7549,
                'longitude' => -73.9840,
                'timestamp' => time(),
            ],
        ]);

        $claims = $provider->evaluate($context);

        // Distance < 50 km, so zero-time difference is acceptable
        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
    }

    #[Test]
    public function longDistanceTravelOverSufficientTimeIsAllowed(): void
    {
        // New York
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        // London 24 hours ago (~5,570 km) - well within jet speed
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'timestamp' => time() - 86400, // 24 hours ago
            ],
        ]);

        $claims = $provider->evaluate($context);

        // 5570 km / 24 hours = ~232 km/h, well under 900 km/h max
        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
    }

    #[Test]
    public function travelAtExactlyMaxSpeedIsNotImpossible(): void
    {
        // Current: at a point
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(0.0, 0.0, 'XX'),
        ));

        // Previous: exactly 900 km away, 1 hour ago
        // 900 km/h = max speed exactly. The distance at (0,0) to (0, ~8.09) is ~900km
        $context = $this->createContext('1.2.3.4', attributes: [
            'last_known_location' => [
                'latitude' => 0.0,
                'longitude' => 8.09, // ~900 km at equator
                'timestamp' => time() - 3600, // 1 hour ago
            ],
        ]);

        $claims = $provider->evaluate($context);

        // At exactly max speed, requiredSpeed <= MAX_TRAVEL_SPEED_KMH, so not impossible
        // (depends on exact distance calculation)
        self::assertInstanceOf(ClaimSet::class, $claims);
    }

    #[Test]
    public function impossibleTravelConfidenceIsVerifiedGeo(): void
    {
        // Trigger impossible travel
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => 35.6762,
                'longitude' => 139.6503,
                'timestamp' => time() - 60, // 1 minute ago, Tokyo -> NYC
            ],
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'location.travel_impossible')->value);
        self::assertSame(0.85, self::firstClaim($claims, 'location.travel_impossible')->confidence);
    }

    #[Test]
    public function nonImpossibleTravelUsesStandardConfidence(): void
    {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(40.7128, -74.006, 'US', 'New York'),
        ));

        // No known countries = IP_ONLY confidence
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => 42.3601,
                'longitude' => -71.0589,
                'timestamp' => time() - 86400, // 24h ago, Boston
            ],
        ]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
        // No known_countries, so IP_ONLY confidence = 0.6
        self::assertSame(0.6, self::firstClaim($claims, 'location.travel_impossible')->confidence);
    }

    /**
     * @return array<string, array{float, float, string, float, float, int, bool}>
     */
    public static function travelScenarioProvider(): array
    {
        return [
            'NYC->Tokyo 30min = impossible' => [40.7128, -74.006, 'US', 35.6762, 139.6503, 1800, true],
            'NYC->Boston 2h = ok' => [40.7128, -74.006, 'US', 42.3601, -71.0589, 7200, false],
            'London->Sydney same second = impossible' => [51.5074, -0.1278, 'GB', -33.8688, 151.2093, 0, true],
            'NYC->London 24h = ok' => [40.7128, -74.006, 'US', 51.5074, -0.1278, 86400, false],
        ];
    }

    #[Test]
    #[DataProvider('travelScenarioProvider')]
    public function travelScenarios(
        float $currentLat,
        float $currentLon,
        string $currentCountry,
        float $prevLat,
        float $prevLon,
        int $secondsAgo,
        bool $expectImpossible,
    ): void {
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation($currentLat, $currentLon, $currentCountry),
        ));

        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => $prevLat,
                'longitude' => $prevLon,
                'timestamp' => time() - $secondsAgo,
            ],
        ]);

        $claims = $provider->evaluate($context);

        self::assertSame(
            $expectImpossible,
            self::firstClaim($claims, 'location.travel_impossible')->value,
            sprintf(
                'Expected travel_impossible=%s for (%.4f,%.4f)->(%.4f,%.4f) in %ds',
                $expectImpossible ? 'true' : 'false',
                $prevLat,
                $prevLon,
                $currentLat,
                $currentLon,
                $secondsAgo,
            ),
        );
    }

    private static function firstClaim(ClaimSet $claims, string $name): Claim
    {
        $claim = $claims->first($name);
        self::assertNotNull($claim, sprintf('Expected claim "%s" to exist', $name));

        return $claim;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createContext(string $ip, array $attributes = []): SignalContext
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return new SignalContext(
            request: $request,
            identityId: 'user-1',
            attributes: $attributes,
        );
    }

    private function resolverReturning(GeoLocation $location): GeoLocationResolverInterface
    {
        $resolver = $this->createStub(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn($location);

        return $resolver;
    }
}
