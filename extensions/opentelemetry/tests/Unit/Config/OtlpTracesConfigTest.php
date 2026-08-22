<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;
use Pulsar\Extension\OpenTelemetry\Config\OtlpTracesConfig;

#[CoversClass(OtlpTracesConfig::class)]
final class OtlpTracesConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new OtlpTracesConfig();

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame([], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'https://traces.example.com:4318/v1/traces',
            'attribute_allowlist' => ['tracing' => ['http.method', 'http.url']],
            'db_statement_export' => 'hash',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('https://traces.example.com:4318/v1/traces', $config->endpoint);
        self::assertSame(['tracing' => ['http.method', 'http.url']], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::Hash, $config->dbStatementExport);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = OtlpTracesConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'enabled' => 'yes',
            'endpoint' => 123,
            'attribute_allowlist' => 'not-array',
            'db_statement_export' => 999,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame([], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function fromArrayWithInvalidDbStatementExportString(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'db_statement_export' => 'unknown_mode',
        ]);

        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function fromArrayWithFullDbStatementExport(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'db_statement_export' => 'full',
        ]);

        self::assertSame(DbStatementExport::Full, $config->dbStatementExport);
    }
}
