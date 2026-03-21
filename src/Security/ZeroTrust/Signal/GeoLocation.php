<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use Pulsar\Api\Api;

/**
 * Geolocation data resolved from an IP address.
 *
 * Used by the location signal provider to detect geographic anomalies
 * and impossible travel scenarios.
 */
#[Api(since: '1.0.0')]
final readonly class GeoLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $country,
        public string $city = '',
    ) {}

    /**
     * Calculate the great-circle distance to another location in kilometers.
     *
     * Uses the Haversine formula for accuracy at all distances.
     */
    public function distanceTo(self $other): float
    {
        $earthRadiusKm = 6371.0;

        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $lonDiff = deg2rad($other->longitude - $this->longitude);
        $latDiff = deg2rad($other->latitude - $this->latitude);

        $sinHalfLat = sin($latDiff / 2.0);
        $sinHalfLon = sin($lonDiff / 2.0);

        $a = $sinHalfLat * $sinHalfLat
            + cos($latFrom) * cos($latTo) * $sinHalfLon * $sinHalfLon;

        return 2.0 * $earthRadiusKm * asin(sqrt($a));
    }
}
