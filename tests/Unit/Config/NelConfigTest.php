<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\NelConfig;

#[CoversClass(NelConfig::class)]
final class NelConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new NelConfig();

        self::assertFalse($config->enabled);
        self::assertSame('default', $config->reportTo);
        self::assertSame(86400, $config->maxAge);
        self::assertFalse($config->includeSubdomains);
        self::assertSame(0.0, $config->successFraction);
        self::assertSame(1.0, $config->failureFraction);
    }

    #[Test]
    public function toHeaderValueReturnsEmptyWhenDisabled(): void
    {
        $config = new NelConfig(enabled: false);

        self::assertSame('', $config->toHeaderValue());
    }

    #[Test]
    public function toHeaderValueReturnsJsonWhenEnabled(): void
    {
        $config = new NelConfig(
            enabled: true,
            reportTo: 'network-errors',
            maxAge: 3600,
            includeSubdomains: true,
            successFraction: 0.01,
            failureFraction: 1.0,
        );

        $value = $config->toHeaderValue();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('network-errors', $decoded['report_to']);
        self::assertSame(3600, $decoded['max_age']);
        self::assertTrue($decoded['include_subdomains']);
        self::assertEqualsWithDelta(0.01, $decoded['success_fraction'], 0.001);
        self::assertEqualsWithDelta(1.0, $decoded['failure_fraction'], 0.001);
    }

    #[Test]
    public function toReportToHeaderValueReturnsEmptyWhenDisabled(): void
    {
        $config = new NelConfig(enabled: false);

        self::assertSame('', $config->toReportToHeaderValue('https://example.com/report'));
    }

    #[Test]
    public function toReportToHeaderValueReturnsEmptyWithEmptyUrl(): void
    {
        $config = new NelConfig(enabled: true);

        self::assertSame('', $config->toReportToHeaderValue(''));
    }

    #[Test]
    public function toReportToHeaderValueReturnsJsonWhenEnabledWithUrl(): void
    {
        $config = new NelConfig(
            enabled: true,
            reportTo: 'nel-group',
            maxAge: 7200,
            includeSubdomains: false,
        );

        $value = $config->toReportToHeaderValue('https://example.com/nel');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('nel-group', $decoded['group']);
        self::assertSame(7200, $decoded['max_age']);
        self::assertFalse($decoded['include_subdomains']);
        /** @var list<array<string, string>> $endpoints */
        $endpoints = $decoded['endpoints'];
        self::assertCount(1, $endpoints);
        self::assertSame('https://example.com/nel', $endpoints[0]['url']);
    }

    #[Test]
    public function fromArrayCreatesWithDefaults(): void
    {
        $config = NelConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('default', $config->reportTo);
        self::assertSame(86400, $config->maxAge);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = NelConfig::fromArray([
            'enabled' => true,
            'report_to' => 'custom-group',
            'max_age' => 1800,
            'include_subdomains' => true,
            'success_fraction' => 0.05,
            'failure_fraction' => 0.5,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('custom-group', $config->reportTo);
        self::assertSame(1800, $config->maxAge);
        self::assertTrue($config->includeSubdomains);
        self::assertSame(0.05, $config->successFraction);
        self::assertSame(0.5, $config->failureFraction);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = NelConfig::fromArray([
            'report_to' => 123,
            'max_age' => 'invalid',
            'success_fraction' => 'bad',
            'failure_fraction' => [],
        ]);

        self::assertSame('default', $config->reportTo);
        self::assertSame(86400, $config->maxAge);
        self::assertSame(0.0, $config->successFraction);
        self::assertSame(1.0, $config->failureFraction);
    }
}
