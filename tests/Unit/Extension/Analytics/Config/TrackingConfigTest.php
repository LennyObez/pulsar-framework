<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\TrackingConfig;

#[CoversClass(TrackingConfig::class)]
final class TrackingConfigTest extends TestCase
{
    #[Test]
    public function defaultConstruction(): void
    {
        $config = new TrackingConfig();

        self::assertSame('/plsr/api/event', $config->trackerEndpoint);
        self::assertSame('/plsr/js/tracker.js', $config->scriptEndpoint);
        self::assertSame([], $config->extensions);
    }

    #[Test]
    public function fromArrayWithValues(): void
    {
        $config = TrackingConfig::fromArray([
            'tracker_endpoint' => '/api/collect',
            'script_endpoint' => '/js/analytics.js',
            'extensions' => ['spa', 'outbound-links'],
        ]);

        self::assertSame('/api/collect', $config->trackerEndpoint);
        self::assertSame('/js/analytics.js', $config->scriptEndpoint);
        self::assertSame(['spa', 'outbound-links'], $config->extensions);
    }

    #[Test]
    public function fromArrayWithEmptyData(): void
    {
        $config = TrackingConfig::fromArray([]);

        self::assertSame('/plsr/api/event', $config->trackerEndpoint);
        self::assertSame('/plsr/js/tracker.js', $config->scriptEndpoint);
        self::assertSame([], $config->extensions);
    }

    #[Test]
    public function fromArrayFiltersInvalidExtensions(): void
    {
        $config = TrackingConfig::fromArray([
            'extensions' => ['spa', '', null, 42, 'outbound-links'],
        ]);

        self::assertSame(['spa', 'outbound-links'], $config->extensions);
    }

    #[Test]
    public function fromArrayCastsNonStringEndpointsToString(): void
    {
        $config = TrackingConfig::fromArray([
            'tracker_endpoint' => 123,
            'script_endpoint' => false,
            'extensions' => 'not-array',
        ]);

        // (string) 123 = '123', (string) false = '', no type-guarded fallback
        self::assertSame('123', $config->trackerEndpoint);
        self::assertSame('', $config->scriptEndpoint);
        // (array) 'not-array' = ['not-array'], is_string passes -> kept
        self::assertSame(['not-array'], $config->extensions);
    }
}
