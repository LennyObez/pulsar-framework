<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Config\ObservabilityConfig;
use Pulsar\Extension\Observability\Integration\HealthCheck;

#[CoversClass(HealthCheck::class)]
final class HealthCheckTest extends TestCase
{
    #[Test]
    public function healthCheckWithDisabledOtlpReportsHealthy(): void
    {
        $config = new ObservabilityConfig(enabled: false);
        $healthCheck = new HealthCheck($config);

        $result = $healthCheck->check();

        self::assertTrue($result['healthy']);
        self::assertSame('pass', $result['checks']['otlp_configured']['status']);
        self::assertStringContainsString('disabled', $result['checks']['otlp_configured']['detail']);
    }

    #[Test]
    public function healthCheckWithEnabledOtlpReportsConfigured(): void
    {
        $config = new ObservabilityConfig(enabled: true);
        $healthCheck = new HealthCheck($config);

        $result = $healthCheck->check();

        self::assertTrue($result['healthy']);
        self::assertSame('pass', $result['checks']['otlp_configured']['status']);
        self::assertStringContainsString('enabled', $result['checks']['otlp_configured']['detail']);
    }

    #[Test]
    public function healthCheckReportsJsonLinesStatus(): void
    {
        $config = new ObservabilityConfig(enabled: false);
        $healthCheck = new HealthCheck($config);

        $result = $healthCheck->check();

        self::assertArrayHasKey('jsonlines_configured', $result['checks']);
        self::assertSame('pass', $result['checks']['jsonlines_configured']['status']);
    }
}
