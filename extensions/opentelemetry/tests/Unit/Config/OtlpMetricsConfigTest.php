<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\OtlpMetricsConfig;

#[CoversClass(OtlpMetricsConfig::class)]
final class OtlpMetricsConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new OtlpMetricsConfig();

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(60_000, $config->collectIntervalMs);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = OtlpMetricsConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'https://metrics.example.com:4318/v1/metrics',
            'collect_interval_ms' => 30_000,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('https://metrics.example.com:4318/v1/metrics', $config->endpoint);
        self::assertSame(30_000, $config->collectIntervalMs);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = OtlpMetricsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(60_000, $config->collectIntervalMs);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = OtlpMetricsConfig::fromArray([
            'enabled' => 'true',
            'endpoint' => 42,
            'collect_interval_ms' => 'fast',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(60_000, $config->collectIntervalMs);
    }
}
