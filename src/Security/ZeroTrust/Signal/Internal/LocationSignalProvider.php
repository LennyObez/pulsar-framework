<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal\Internal;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

use function in_array;
use function is_string;

/**
 * Produces location-related trust claims.
 *
 * Compares the current request's IP geolocation against the user's known locations
 * and recent activity to detect geographic anomalies and impossible travel.
 *
 * Claims produced:
 * - `location.country` (string): Resolved country code
 * - `location.geo_anomaly` (bool): Whether the location is unusual for this identity
 * - `location.travel_impossible` (bool): Whether the distance/time combination is physically impossible
 */
#[Internal]
final readonly class LocationSignalProvider implements SignalProviderInterface
{
    private const float CONFIDENCE_VERIFIED_GEO = 0.85;
    private const float CONFIDENCE_IP_ONLY = 0.6;

    /** Maximum plausible travel speed in km/h (commercial jet). */
    private const float MAX_TRAVEL_SPEED_KMH = 900.0;

    public function __construct(
        private ?GeoLocationResolverInterface $geoResolver = null,
    ) {}

    public function evaluate(SignalContext $context): ClaimSet
    {
        $now = new DateTimeImmutable();

        if ($this->geoResolver === null) {
            return $this->degradedClaims($now);
        }

        $ip = $this->extractClientIp($context);
        $location = $this->geoResolver->resolve($ip);

        if ($location === null) {
            return $this->degradedClaims($now);
        }

        /** @var list<string> $knownCountries */
        $knownCountries = $context->attribute('known_countries', []);
        $isAnomalous = $knownCountries !== [] && !in_array($location->country, $knownCountries, true);

        $travelImpossible = $this->detectImpossibleTravel($context, $location);

        $confidence = $knownCountries !== [] ? self::CONFIDENCE_VERIFIED_GEO : self::CONFIDENCE_IP_ONLY;

        return new ClaimSet([
            new Claim(
                name: 'location.country',
                value: $location->country,
                source: ClaimSource::LocationSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'location.geo_anomaly',
                value: $isAnomalous,
                source: ClaimSource::LocationSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'location.travel_impossible',
                value: $travelImpossible,
                source: ClaimSource::LocationSignal,
                confidence: $travelImpossible ? self::CONFIDENCE_VERIFIED_GEO : $confidence,
                timestamp: $now,
            ),
        ]);
    }

    public function name(): string
    {
        return 'location';
    }

    private function extractClientIp(SignalContext $context): string
    {
        $serverParams = $context->request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddr) ? $remoteAddr : '127.0.0.1';
    }

    private function detectImpossibleTravel(SignalContext $context, GeoLocation $currentLocation): bool
    {
        /** @var array{latitude: float, longitude: float, timestamp: int}|null $lastLocation */
        $lastLocation = $context->attribute('last_known_location');

        if ($lastLocation === null) {
            return false;
        }

        $previousLocation = new GeoLocation(
            latitude: $lastLocation['latitude'],
            longitude: $lastLocation['longitude'],
            country: '',
        );

        $distanceKm = $currentLocation->distanceTo($previousLocation);
        $timeDiffHours = (float) (time() - $lastLocation['timestamp']) / 3600.0;

        if ($timeDiffHours <= 0.0) {
            return $distanceKm > 50.0;
        }

        $requiredSpeedKmh = $distanceKm / $timeDiffHours;

        return $requiredSpeedKmh > self::MAX_TRAVEL_SPEED_KMH;
    }

    private function degradedClaims(DateTimeImmutable $now): ClaimSet
    {
        return new ClaimSet([
            new Claim(
                name: 'location.country',
                value: 'unknown',
                source: ClaimSource::LocationSignal,
                confidence: 0.0,
                timestamp: $now,
            ),
            new Claim(
                name: 'location.geo_anomaly',
                value: false,
                source: ClaimSource::LocationSignal,
                confidence: 0.0,
                timestamp: $now,
            ),
            new Claim(
                name: 'location.travel_impossible',
                value: false,
                source: ClaimSource::LocationSignal,
                confidence: 0.0,
                timestamp: $now,
            ),
        ]);
    }
}
