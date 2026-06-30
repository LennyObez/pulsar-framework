<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Config\GitHubIntegrityConfig;
use Pulsar\Extension\HealthStatus\Config\HealthStatusConfig;
use Pulsar\Extension\HealthStatus\Config\HistoryRetentionConfig;

#[CoversClass(HealthStatusConfig::class)]
final class HealthStatusConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $config = new HealthStatusConfig();

        self::assertTrue($config->enabled);
        self::assertSame('/_pulsar/status', $config->routePrefix);
        self::assertSame(60, $config->snapshotIntervalSeconds);
        self::assertInstanceOf(HistoryRetentionConfig::class, $config->retention);
        self::assertInstanceOf(GitHubIntegrityConfig::class, $config->github);
        self::assertTrue($config->requireAuth);
        self::assertFalse($config->publicSummary);
        self::assertSame(30, $config->rateLimitPerMinute);
        self::assertSame(3, $config->incidentThresholdConsecutiveFailures);
        // No token by default — combined with requireAuth this fails closed.
        self::assertNull($config->authToken);
    }

    #[Test]
    public function fromArrayMapsAuthTokenAndTreatsEmptyAsNull(): void
    {
        self::assertSame('s3cret-token', HealthStatusConfig::fromArray(['auth_token' => 's3cret-token'])->authToken);
        self::assertNull(HealthStatusConfig::fromArray(['auth_token' => ''])->authToken);
        self::assertNull(HealthStatusConfig::fromArray([])->authToken);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = HealthStatusConfig::fromArray([
            'enabled' => false,
            'route_prefix' => '/status',
            'snapshot_interval_seconds' => 120,
            'retention' => ['max_age_days' => 7, 'max_rows' => 50000, 'cleanup_interval_hours' => 12],
            'github' => ['enabled' => true, 'repository' => 'org/repo', 'branch' => 'develop'],
            'require_auth' => false,
            'public_summary' => true,
            'rate_limit_per_minute' => 60,
            'incident_threshold_consecutive_failures' => 5,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('/status', $config->routePrefix);
        self::assertSame(120, $config->snapshotIntervalSeconds);
        self::assertSame(7, $config->retention->maxAgeDays);
        self::assertSame(50000, $config->retention->maxRows);
        self::assertSame(12, $config->retention->cleanupIntervalHours);
        self::assertTrue($config->github->enabled);
        self::assertSame('org/repo', $config->github->repository);
        self::assertSame('develop', $config->github->branch);
        self::assertFalse($config->requireAuth);
        self::assertTrue($config->publicSummary);
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertSame(5, $config->incidentThresholdConsecutiveFailures);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = HealthStatusConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('/_pulsar/status', $config->routePrefix);
        self::assertSame(60, $config->snapshotIntervalSeconds);
        self::assertTrue($config->requireAuth);
        self::assertFalse($config->publicSummary);
        self::assertSame(30, $config->rateLimitPerMinute);
        self::assertSame(3, $config->incidentThresholdConsecutiveFailures);
    }

    #[Test]
    public function fromArrayHandlesNonArraySubconfigs(): void
    {
        $config = HealthStatusConfig::fromArray([
            'retention' => 'not-an-array',
            'github' => false,
        ]);

        self::assertSame(30, $config->retention->maxAgeDays);
        self::assertFalse($config->github->enabled);
    }

    /**
     * @return iterable<string, array{string, int|string|bool}>
     */
    public static function scalarFieldsProvider(): iterable
    {
        yield 'enabled true' => ['enabled', true];
        yield 'enabled false' => ['enabled', false];
        yield 'route prefix custom' => ['route_prefix', '/custom'];
        yield 'snapshot interval 30' => ['snapshot_interval_seconds', 30];
        yield 'snapshot interval 300' => ['snapshot_interval_seconds', 300];
        yield 'rate limit 1' => ['rate_limit_per_minute', 1];
        yield 'rate limit 100' => ['rate_limit_per_minute', 100];
        yield 'threshold 1' => ['incident_threshold_consecutive_failures', 1];
        yield 'threshold 10' => ['incident_threshold_consecutive_failures', 10];
    }

    #[Test]
    #[DataProvider('scalarFieldsProvider')]
    public function fromArrayPreservesScalarField(string $key, int|string|bool $value): void
    {
        $config = HealthStatusConfig::fromArray([$key => $value]);

        $property = match ($key) {
            'enabled' => $config->enabled,
            'route_prefix' => $config->routePrefix,
            'snapshot_interval_seconds' => $config->snapshotIntervalSeconds,
            'rate_limit_per_minute' => $config->rateLimitPerMinute,
            'incident_threshold_consecutive_failures' => $config->incidentThresholdConsecutiveFailures,
            default => self::fail("Unknown key: {$key}"),
        };

        self::assertSame($value, $property);
    }
}
