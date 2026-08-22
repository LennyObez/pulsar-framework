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
    public function fromArrayRejectsNonStringEndpointsAndFallsBack(): void
    {
        $config = TrackingConfig::fromArray([
            'tracker_endpoint' => 123,
            'script_endpoint' => false,
            'extensions' => 'not-array',
        ]);

        // is_string(123) = false, key is set → falls back to ''
        self::assertSame('', $config->trackerEndpoint);
        // is_string(false) = false, key is set → falls back to ''
        self::assertSame('', $config->scriptEndpoint);
        // (array) 'not-array' = ['not-array'], is_string('not-array') = true → kept
        self::assertSame(['not-array'], $config->extensions);
    }
}
