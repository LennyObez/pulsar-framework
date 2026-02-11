<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;
use Pulsar\Extension\OpenTelemetry\Config\OtlpLogsConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpMetricsConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpTracesConfig;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(OtlpLogsConfig::class)]
#[CoversClass(OtlpMetricsConfig::class)]
#[CoversClass(OtlpTracesConfig::class)]
final class OtlpSignalConfigsTest extends TestCase
{
    // --- OtlpLogsConfig ---

    #[Test]
    public function logsConfigDefaults(): void
    {
        $config = OtlpLogsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function logsConfigFromArray(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'http://collector:4318/v1/logs',
            'min_level' => 'error',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://collector:4318/v1/logs', $config->endpoint);
        self::assertSame(LogLevel::Error, $config->minLevel);
    }

    #[Test]
    public function logsConfigInvalidMinLevelFallsBackToWarning(): void
    {
        $config = OtlpLogsConfig::fromArray(['min_level' => 'nonexistent']);

        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function logsConfigInvalidTypesUseDefaults(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'enabled' => 'yes',
            'endpoint' => 123,
            'min_level' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    // --- OtlpMetricsConfig ---

    #[Test]
    public function metricsConfigDefaults(): void
    {
        $config = OtlpMetricsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(60_000, $config->collectIntervalMs);
    }

    #[Test]
    public function metricsConfigFromArray(): void
    {
        $config = OtlpMetricsConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'http://collector:4318/v1/metrics',
            'collect_interval_ms' => 30_000,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://collector:4318/v1/metrics', $config->endpoint);
        self::assertSame(30_000, $config->collectIntervalMs);
    }

    #[Test]
    public function metricsConfigInvalidTypesUseDefaults(): void
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

    // --- OtlpTracesConfig ---

    #[Test]
    public function tracesConfigDefaults(): void
    {
        $config = OtlpTracesConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame([], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function tracesConfigFromArray(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'http://collector:4318/v1/traces',
            'attribute_allowlist' => ['http' => ['method', 'status_code']],
            'db_statement_export' => 'hash',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://collector:4318/v1/traces', $config->endpoint);
        self::assertSame(['http' => ['method', 'status_code']], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::Hash, $config->dbStatementExport);
    }

    #[Test]
    public function tracesConfigDbStatementExportFull(): void
    {
        $config = OtlpTracesConfig::fromArray(['db_statement_export' => 'full']);

        self::assertSame(DbStatementExport::Full, $config->dbStatementExport);
    }

    #[Test]
    public function tracesConfigInvalidDbStatementExportFallsBackToNone(): void
    {
        $config = OtlpTracesConfig::fromArray(['db_statement_export' => 'invalid']);

        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function tracesConfigInvalidTypesUseDefaults(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'enabled' => 'yes',
            'endpoint' => 42,
            'attribute_allowlist' => 'not-an-array',
            'db_statement_export' => 123,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame([], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }
}
