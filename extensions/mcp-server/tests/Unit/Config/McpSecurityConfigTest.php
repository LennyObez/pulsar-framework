<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;

final class McpSecurityConfigTest extends TestCase
{
    #[Test]
    public function fromArrayBuildsConfig(): void
    {
        $config = McpSecurityConfig::fromArray([
            'path_allowlist' => ['src/**', 'config/**'],
            'rate_limit_per_minute' => 120,
            'tool_rate_limits' => ['run_tests' => 5],
            'max_concurrent_actions' => 3,
        ]);

        self::assertSame(['src/**', 'config/**'], $config->pathAllowlist);
        self::assertSame(120, $config->rateLimitPerMinute);
        self::assertSame(['run_tests' => 5], $config->toolRateLimits);
        self::assertSame(3, $config->maxConcurrentActions);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = McpSecurityConfig::fromArray([]);

        self::assertSame([], $config->pathAllowlist);
        self::assertSame(60, $config->rateLimitPerMinute);
        self::assertSame([], $config->toolRateLimits);
        self::assertSame(1, $config->maxConcurrentActions);
    }

    #[Test]
    public function fromArrayHandlesNonIntRateLimit(): void
    {
        $config = McpSecurityConfig::fromArray([
            'rate_limit_per_minute' => 'not-an-int',
        ]);

        self::assertSame(60, $config->rateLimitPerMinute);
    }
}
