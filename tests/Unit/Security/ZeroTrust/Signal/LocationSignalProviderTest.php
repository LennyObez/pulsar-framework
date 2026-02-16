<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
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

#[CoversClass(LocationSignalProvider::class)]
final class LocationSignalProviderTest extends TestCase
{
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
    public function noResolverReturnsDegradedClaims(): void
    {
        $provider = new LocationSignalProvider();
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertCount(3, $claims);
        self::assertSame('unknown', self::firstClaim($claims, 'location.country')->value);
        self::assertSame(0.0, self::firstClaim($claims, 'location.country')->confidence);
        self::assertFalse(self::firstClaim($claims, 'location.geo_anomaly')->value);
        self::assertFalse(self::firstClaim($claims, 'location.travel_impossible')->value);
    }

    #[Test]
    public function unresolvedIpReturnsDegradedClaims(): void
    {
        $resolver = $this->createMock(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $provider = new LocationSignalProvider($resolver);
        $claims = $provider->evaluate($this->createContext('127.0.0.1'));

        self::assertSame('unknown', self::firstClaim($claims, 'location.country')->value);
        self::assertSame(0.0, self::firstClaim($claims, 'location.country')->confidence);
    }

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
    public function detectsImpossibleTravel(): void
    {
        // New York
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
    }

    #[Test]
    public function noImpossibleTravelForReasonableDistance(): void
    {
        // New York
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
        // London
        $provider = new LocationSignalProvider($this->resolverReturning(
            new GeoLocation(51.5074, -0.1278, 'GB', 'London'),
        ));

        // Previous login in Sydney at the same second
        $context = $this->createContext('203.0.113.1', attributes: [
            'last_known_location' => [
                'latitude' => -33.8688,
                'longitude' => 151.2093,
                'timestamp' => time(),
            ],
        ]);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'location.travel_impossible')->value);
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
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return new SignalContext(
            request: $request,
            identityId: 'user-1',
            attributes: $attributes,
        );
    }

    private function resolverReturning(GeoLocation $location): GeoLocationResolverInterface
    {
        $resolver = $this->createMock(GeoLocationResolverInterface::class);
        $resolver->method('resolve')->willReturn($location);

        return $resolver;
    }
}
