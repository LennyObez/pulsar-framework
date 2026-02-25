<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Config\TrackingConfig;

final class TrackingConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new TrackingConfig();

        self::assertSame('/plsr/api/event', $config->trackerEndpoint);
        self::assertSame('/plsr/js/tracker.js', $config->scriptEndpoint);
        self::assertSame([], $config->extensions);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = TrackingConfig::fromArray([
            'tracker_endpoint' => '/custom/event',
            'script_endpoint' => '/custom/tracker.js',
            'extensions' => ['spa', 'outbound-links'],
        ]);

        self::assertSame('/custom/event', $config->trackerEndpoint);
        self::assertSame('/custom/tracker.js', $config->scriptEndpoint);
        self::assertSame(['spa', 'outbound-links'], $config->extensions);
    }

    #[Test]
    public function fromArrayFiltersNonStringExtensions(): void
    {
        $config = TrackingConfig::fromArray([
            'extensions' => ['spa', 42, '', null, 'outbound-links'],
        ]);

        self::assertSame(['spa', 'outbound-links'], $config->extensions);
    }

    #[Test]
    public function fromArrayHandlesNonStringEndpoints(): void
    {
        $config = TrackingConfig::fromArray([
            'tracker_endpoint' => 123,
            'script_endpoint' => false,
        ]);

        self::assertSame('/plsr/api/event', $config->trackerEndpoint);
        self::assertSame('/plsr/js/tracker.js', $config->scriptEndpoint);
    }

    #[Test]
    public function fromArrayHandlesNonArrayExtensions(): void
    {
        $config = TrackingConfig::fromArray([
            'extensions' => 'not-an-array',
        ]);

        self::assertSame([], $config->extensions);
    }
}
