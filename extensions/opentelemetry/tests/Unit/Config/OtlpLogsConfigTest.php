<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\OtlpLogsConfig;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(OtlpLogsConfig::class)]
final class OtlpLogsConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new OtlpLogsConfig();

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function fromArrayWithValidData(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'https://logs.example.com:4318/v1/logs',
            'min_level' => 'error',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('https://logs.example.com:4318/v1/logs', $config->endpoint);
        self::assertSame(LogLevel::Error, $config->minLevel);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = OtlpLogsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function fromArrayWithInvalidMinLevelFallsBackToWarning(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'min_level' => 'nonexistent',
        ]);

        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function fromArrayWithNonStringMinLevelFallsBackToWarning(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'min_level' => 42,
        ]);

        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'enabled' => 'yes',
            'endpoint' => [],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
    }

    #[Test]
    public function fromArrayWithDebugLevel(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'min_level' => 'debug',
        ]);

        self::assertSame(LogLevel::Debug, $config->minLevel);
    }
}
