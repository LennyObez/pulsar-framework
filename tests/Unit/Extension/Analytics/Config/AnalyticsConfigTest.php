<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;
use Pulsar\Extension\Analytics\Config\RateLimitConfig;
use Pulsar\Extension\Analytics\Config\RetentionConfig;
use Pulsar\Extension\Analytics\Config\TrackingConfig;

#[CoversClass(AnalyticsConfig::class)]
final class AnalyticsConfigTest extends TestCase
{
    #[Test]
    public function defaultConstruction(): void
    {
        $config = new AnalyticsConfig();

        self::assertTrue($config->enabled);
        self::assertSame('direct', $config->collectionDriver);
        self::assertSame([], $config->trustedProxies);
        self::assertInstanceOf(PrivacyConfig::class, $config->privacy);
        self::assertInstanceOf(TrackingConfig::class, $config->tracking);
        self::assertInstanceOf(RetentionConfig::class, $config->retention);
        self::assertInstanceOf(RateLimitConfig::class, $config->rateLimit);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = AnalyticsConfig::fromArray([
            'enabled' => false,
            'collection' => ['driver' => 'queue'],
            'trusted_proxies' => ['10.0.0.1', '10.0.0.2'],
            'privacy' => ['respect_dnt' => true, 'anonymize_referrer' => true],
            'tracking' => ['tracker_endpoint' => '/custom/track'],
            'retention' => ['raw_days' => 30],
            'rate_limit' => ['max_events_per_ip_per_minute' => 60],
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('queue', $config->collectionDriver);
        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
        self::assertTrue($config->privacy->respectDnt);
        self::assertTrue($config->privacy->anonymizeReferrer);
        self::assertSame('/custom/track', $config->tracking->trackerEndpoint);
        self::assertSame(30, $config->retention->rawDays);
        self::assertSame(60, $config->rateLimit->maxEventsPerIpPerMinute);
    }

    #[Test]
    public function fromArrayWithEmptyData(): void
    {
        $config = AnalyticsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('direct', $config->collectionDriver);
        self::assertSame([], $config->trustedProxies);
    }

    #[Test]
    public function fromArrayFiltersInvalidProxies(): void
    {
        $config = AnalyticsConfig::fromArray([
            'trusted_proxies' => ['10.0.0.1', '', null, 42, '192.168.1.1'],
        ]);

        self::assertSame(['10.0.0.1', '192.168.1.1'], $config->trustedProxies);
    }

    #[Test]
    public function fromArrayHandlesNonArraySubkeys(): void
    {
        $config = AnalyticsConfig::fromArray([
            'collection' => 'invalid',
            'trusted_proxies' => 'not-array',
            'privacy' => 'invalid',
            'tracking' => null,
            'retention' => 42,
            'rate_limit' => true,
        ]);

        // (array) 'invalid' = ['invalid'], no 'driver' key -> falls back to 'direct'
        self::assertSame('direct', $config->collectionDriver);
        // (array) 'not-array' = ['not-array'], is_string passes -> kept as proxy entry
        self::assertSame(['not-array'], $config->trustedProxies);
        // Non-array privacy value falls back to default PrivacyConfig (respectDnt=true)
        self::assertTrue($config->privacy->respectDnt);
    }

    #[Test]
    public function fromArrayRejectsNonStringDriverAndFallsBackToDefault(): void
    {
        $config = AnalyticsConfig::fromArray([
            'collection' => ['driver' => 123],
        ]);

        // is_string(123) = false → falls back to 'direct'
        self::assertSame('direct', $config->collectionDriver);
    }
}
