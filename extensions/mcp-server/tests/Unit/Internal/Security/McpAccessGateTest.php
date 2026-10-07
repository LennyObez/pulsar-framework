<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Security\McpAccessGate;

final class McpAccessGateTest extends TestCase
{
    #[Test]
    public function local_environment_always_allowed(): void
    {
        $gate = $this->createGate(mode: EnvironmentMode::Local);

        $gate->assertEnvironmentAllowed();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function path_traversal_blocked(): void
    {
        $gate = $this->createGate();

        $this->expectException(McpSecurityException::class);

        $gate->assertPathAllowed('../../../etc/passwd');
    }

    #[Test]
    public function concurrency_limited_at_max(): void
    {
        $config = new McpSecurityConfig(
            pathAllowlist: ['*'],
            rateLimitPerMinute: 60,
            toolRateLimits: [],
            maxConcurrentActions: 1,
        );

        $gate = $this->createGate(config: $config);

        $gate->assertConcurrencyAllowed();

        $this->expectException(McpSecurityException::class);
        $gate->assertConcurrencyAllowed();
    }

    #[Test]
    public function release_concurrency_slot_allows_new_action(): void
    {
        $config = new McpSecurityConfig(
            pathAllowlist: ['*'],
            rateLimitPerMinute: 60,
            toolRateLimits: [],
            maxConcurrentActions: 1,
        );

        $gate = $this->createGate(config: $config);

        $gate->assertConcurrencyAllowed();
        $gate->releaseConcurrencySlot();
        $gate->assertConcurrencyAllowed();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function release_does_not_go_below_zero(): void
    {
        $gate = $this->createGate();

        $gate->releaseConcurrencySlot();
        $gate->releaseConcurrencySlot();

        $this->addToAssertionCount(1);
    }

    private function createGate(
        ?McpSecurityConfig $config = null,
        EnvironmentMode $mode = EnvironmentMode::Local,
    ): McpAccessGate {
        $config ??= new McpSecurityConfig(
            pathAllowlist: ['src/*', 'tests/*'],
            rateLimitPerMinute: 60,
            toolRateLimits: [],
            maxConcurrentActions: 5,
        );

        $env = Environment::load();

        return new McpAccessGate(
            securityConfig: $config,
            mode: $mode,
            environment: $env,
            projectRoot: __DIR__,
        );
    }
}
