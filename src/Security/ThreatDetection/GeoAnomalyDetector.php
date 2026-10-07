<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Signal\GeoLocation;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

use function sprintf;
use function time;

/**
 * Detects impossible travel scenarios.
 *
 * When a user authenticates from two geographically distant locations
 * within a timeframe that makes physical travel impossible (e.g., Paris
 * then Tokyo within 30 minutes), this indicates credential compromise.
 *
 * Compliance: DORA Art.17 (incident detection), ISO 27001 A.8.16.
 * @api
 */
#[Api(since: '1.0.0')]
final class GeoAnomalyDetector implements ThreatDetectorInterface
{
    /** @var array<string, array{location: GeoLocation, timestamp: int}> userId => last known location */
    private array $lastLocations = [];

    public function __construct(
        private readonly GeoLocationResolverInterface $geoResolver,
        private readonly int $maxTravelSpeedKmh,
    ) {}

    #[Override]
    public function analyze(ServerRequestInterface $request): ?ThreatEvent
    {
        // Geo anomaly requires a user context: analysis happens via recordEvent.
        return null;
    }

    #[Override]
    public function recordEvent(string $eventType, array $context): void
    {
        // Only relevant for successful authentications.
    }

    /**
     * Check for impossible travel after a successful login.
     *
     * Should be called with the authenticated user's ID and source IP.
     * Returns a ThreatEvent if impossible travel is detected.
     */
    public function checkLogin(string $userId, string $ip): ?ThreatEvent
    {
        $location = $this->geoResolver->resolve($ip);

        if ($location === null) {
            return null;
        }

        $now = time();
        $previous = $this->lastLocations[$userId] ?? null;

        // Update last known location
        $this->lastLocations[$userId] = ['location' => $location, 'timestamp' => $now];

        if ($previous === null) {
            return null;
        }

        $distanceKm = $previous['location']->distanceTo($location);
        $elapsedHours = ($now - $previous['timestamp']) / 3600.0;

        if ($elapsedHours <= 0.0) {
            $elapsedHours = 1.0 / 3600.0; // minimum 1 second
        }

        $requiredSpeedKmh = $distanceKm / $elapsedHours;

        if ($requiredSpeedKmh <= $this->maxTravelSpeedKmh) {
            return null;
        }

        return ThreatEvent::create(
            category: ThreatCategory::GeoAnomaly,
            recommendedAction: ThreatResponse::Challenge,
            sourceIp: $ip,
            description: sprintf('Impossible travel detected for user %s: %.0f km in %.1f hours (%.0f km/h required)', $userId, $distanceKm, $elapsedHours, $requiredSpeedKmh),
            confidence: min(1.0, $requiredSpeedKmh / ($this->maxTravelSpeedKmh * 5)),
            metadata: [
                'user_id' => $userId,
                'distance_km' => $distanceKm,
                'elapsed_hours' => $elapsedHours,
                'required_speed_kmh' => $requiredSpeedKmh,
                'max_speed_kmh' => $this->maxTravelSpeedKmh,
                'from' => [
                    'country' => $previous['location']->country,
                    'city' => $previous['location']->city,
                    'lat' => $previous['location']->latitude,
                    'lon' => $previous['location']->longitude,
                ],
                'to' => [
                    'country' => $location->country,
                    'city' => $location->city,
                    'lat' => $location->latitude,
                    'lon' => $location->longitude,
                ],
            ],
        );
    }
}
