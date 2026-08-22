<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Pulsar\Api\Api;

/**
 * Configuration for the threat detection engine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreatDetectionConfig
{
    /**
     * @param int $bruteForceThreshold      Max failed attempts before triggering
     * @param int $bruteForceWindowSeconds   Time window for brute force detection
     * @param int $stuffingThreshold         Unique usernames per IP before triggering
     * @param int $stuffingWindowSeconds     Time window for credential stuffing detection
     * @param int $apiAbuseThreshold         Requests per window before triggering
     * @param int $apiAbuseWindowSeconds     Time window for API abuse detection
     * @param int $geoTravelSpeedKmh         Max plausible travel speed in km/h
     * @param bool $injectionDetectionEnabled Whether to scan for injection patterns
     */
    public function __construct(
        public bool $enabled = true,
        public int $bruteForceThreshold = 5,
        public int $bruteForceWindowSeconds = 600,
        public int $stuffingThreshold = 10,
        public int $stuffingWindowSeconds = 300,
        public int $apiAbuseThreshold = 100,
        public int $apiAbuseWindowSeconds = 60,
        public int $geoTravelSpeedKmh = 900,
        public bool $injectionDetectionEnabled = true,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     brute_force_threshold?: int,
     *     brute_force_window_seconds?: int,
     *     stuffing_threshold?: int,
     *     stuffing_window_seconds?: int,
     *     api_abuse_threshold?: int,
     *     api_abuse_window_seconds?: int,
     *     geo_travel_speed_kmh?: int,
     *     injection_detection_enabled?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: $data['enabled'] ?? true,
            bruteForceThreshold: $data['brute_force_threshold'] ?? 5,
            bruteForceWindowSeconds: $data['brute_force_window_seconds'] ?? 600,
            stuffingThreshold: $data['stuffing_threshold'] ?? 10,
            stuffingWindowSeconds: $data['stuffing_window_seconds'] ?? 300,
            apiAbuseThreshold: $data['api_abuse_threshold'] ?? 100,
            apiAbuseWindowSeconds: $data['api_abuse_window_seconds'] ?? 60,
            geoTravelSpeedKmh: $data['geo_travel_speed_kmh'] ?? 900,
            injectionDetectionEnabled: $data['injection_detection_enabled'] ?? true,
        );
    }
}
