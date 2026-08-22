<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Config\PrivacyConfig;
use Pulsar\Extension\Analytics\Config\RateLimitConfig;
use Pulsar\Extension\Analytics\Config\RetentionConfig;
use Pulsar\Extension\Analytics\Config\TrackingConfig;

final class AnalyticsConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreApplied(): void
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
            'tracking' => ['tracker_endpoint' => '/custom/event', 'script_endpoint' => '/custom/tracker.js', 'extensions' => ['spa']],
            'retention' => ['raw_days' => 30, 'aggregated_days' => 365, 'hourly_hours' => 24],
            'rate_limit' => ['max_events_per_ip_per_minute' => 60, 'burst' => 10],
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('queue', $config->collectionDriver);
        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
        self::assertTrue($config->privacy->respectDnt);
        self::assertTrue($config->privacy->anonymizeReferrer);
        self::assertSame('/custom/event', $config->tracking->trackerEndpoint);
        self::assertSame('/custom/tracker.js', $config->tracking->scriptEndpoint);
        self::assertSame(['spa'], $config->tracking->extensions);
        self::assertSame(30, $config->retention->rawDays);
        self::assertSame(365, $config->retention->aggregatedDays);
        self::assertSame(24, $config->retention->hourlyHours);
        self::assertSame(60, $config->rateLimit->maxEventsPerIpPerMinute);
        self::assertSame(10, $config->rateLimit->burst);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = AnalyticsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('direct', $config->collectionDriver);
        self::assertSame([], $config->trustedProxies);
    }

    #[Test]
    public function fromArrayFiltersNonStringProxies(): void
    {
        $config = AnalyticsConfig::fromArray([
            'trusted_proxies' => ['10.0.0.1', 42, '', null, '10.0.0.2'],
        ]);

        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
    }

    #[Test]
    public function fromArrayHandlesInvalidCollectionDriver(): void
    {
        $config = AnalyticsConfig::fromArray([
            'collection' => ['driver' => 123],
        ]);

        self::assertSame('direct', $config->collectionDriver);
    }

    #[Test]
    public function fromArrayHandlesNonArraySubconfigs(): void
    {
        $config = AnalyticsConfig::fromArray([
            'privacy' => 'not-an-array',
            'tracking' => false,
            'retention' => null,
            'rate_limit' => 42,
        ]);

        self::assertTrue($config->privacy->respectDnt);
        self::assertSame('/plsr/api/event', $config->tracking->trackerEndpoint);
        self::assertSame(90, $config->retention->rawDays);
        self::assertSame(30, $config->rateLimit->maxEventsPerIpPerMinute);
    }
}
