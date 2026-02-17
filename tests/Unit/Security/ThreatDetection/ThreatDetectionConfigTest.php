<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ThreatDetection\ThreatDetectionConfig;

#[CoversClass(ThreatDetectionConfig::class)]
final class ThreatDetectionConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new ThreatDetectionConfig();

        self::assertTrue($config->enabled);
        self::assertSame(5, $config->bruteForceThreshold);
        self::assertSame(600, $config->bruteForceWindowSeconds);
        self::assertSame(10, $config->stuffingThreshold);
        self::assertSame(300, $config->stuffingWindowSeconds);
        self::assertSame(100, $config->apiAbuseThreshold);
        self::assertSame(60, $config->apiAbuseWindowSeconds);
        self::assertSame(900, $config->geoTravelSpeedKmh);
        self::assertTrue($config->injectionDetectionEnabled);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = ThreatDetectionConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(5, $config->bruteForceThreshold);
        self::assertSame(600, $config->bruteForceWindowSeconds);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = ThreatDetectionConfig::fromArray([
            'enabled' => false,
            'brute_force_threshold' => 10,
            'brute_force_window_seconds' => 1200,
            'stuffing_threshold' => 20,
            'stuffing_window_seconds' => 600,
            'api_abuse_threshold' => 200,
            'api_abuse_window_seconds' => 120,
            'geo_travel_speed_kmh' => 1200,
            'injection_detection_enabled' => false,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(10, $config->bruteForceThreshold);
        self::assertSame(1200, $config->bruteForceWindowSeconds);
        self::assertSame(20, $config->stuffingThreshold);
        self::assertSame(600, $config->stuffingWindowSeconds);
        self::assertSame(200, $config->apiAbuseThreshold);
        self::assertSame(120, $config->apiAbuseWindowSeconds);
        self::assertSame(1200, $config->geoTravelSpeedKmh);
        self::assertFalse($config->injectionDetectionEnabled);
    }

    public function testFromArrayPartialOverrides(): void
    {
        $config = ThreatDetectionConfig::fromArray([
            'brute_force_threshold' => 15,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(15, $config->bruteForceThreshold);
        self::assertSame(600, $config->bruteForceWindowSeconds);
    }
}
