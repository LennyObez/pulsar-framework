<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\MetricsConfig;

#[CoversClass(MetricsConfig::class)]
final class MetricsConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new MetricsConfig();

        self::assertTrue($config->enabled);
        self::assertFalse($config->exporterEnabled);
        self::assertSame('/metrics', $config->exporterEndpoint);
    }

    #[Test]
    public function fromArrayWithOpenmetricsExporter(): void
    {
        $config = MetricsConfig::fromArray([
            'enabled' => true,
            'exporters' => [
                'openmetrics' => [
                    'enabled' => true,
                    'endpoint' => '/custom-metrics',
                ],
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->exporterEnabled);
        self::assertSame('/custom-metrics', $config->exporterEndpoint);
    }

    #[Test]
    public function fromArrayWithLegacyPrometheusKey(): void
    {
        $config = MetricsConfig::fromArray([
            'enabled' => true,
            'exporters' => [
                'prometheus' => [
                    'enabled' => true,
                    'endpoint' => '/prometheus',
                ],
            ],
        ]);

        self::assertTrue($config->exporterEnabled);
        self::assertSame('/prometheus', $config->exporterEndpoint);
    }

    #[Test]
    public function fromArrayPrefersOpenmetricsOverPrometheus(): void
    {
        $config = MetricsConfig::fromArray([
            'exporters' => [
                'openmetrics' => ['enabled' => true, 'endpoint' => '/openmetrics'],
                'prometheus' => ['enabled' => false, 'endpoint' => '/prom'],
            ],
        ]);

        self::assertTrue($config->exporterEnabled);
        self::assertSame('/openmetrics', $config->exporterEndpoint);
    }

    #[Test]
    public function fromArrayWithEmptyData(): void
    {
        $config = MetricsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertFalse($config->exporterEnabled);
        self::assertSame('/metrics', $config->exporterEndpoint);
    }

    #[Test]
    public function fromArrayDisabledMetrics(): void
    {
        $config = MetricsConfig::fromArray(['enabled' => false]);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayNonStringEndpointUsesDefault(): void
    {
        $config = MetricsConfig::fromArray([
            'exporters' => [
                'openmetrics' => [
                    'enabled' => true,
                    'endpoint' => 42,
                ],
            ],
        ]);

        self::assertSame('/metrics', $config->exporterEndpoint);
    }
}
