<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Security\McpAccessGate;

#[CoversClass(McpAccessGate::class)]
final class McpAccessGateTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('MCP_STAGING_CONFIRM');
        putenv('MCP_PRODUCTION_CONFIRM');
    }

    protected function tearDown(): void
    {
        putenv('MCP_STAGING_CONFIRM');
        putenv('MCP_PRODUCTION_CONFIRM');
    }

    #[Test]
    public function localEnvironmentIsAllowed(): void
    {
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([]);

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, '/tmp/project');

        $gate->assertEnvironmentAllowed();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function stagingRequiresConfirmation(): void
    {
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([]);

        $gate = new McpAccessGate($config, EnvironmentMode::Staging, $env, '/tmp/project');

        $this->expectException(McpSecurityException::class);

        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function stagingWithConfirmationPasses(): void
    {
        putenv('MCP_STAGING_CONFIRM=true');

        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([]);

        $gate = new McpAccessGate($config, EnvironmentMode::Staging, $env, '/tmp/project');

        $gate->assertEnvironmentAllowed();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function productionRequiresConfirmation(): void
    {
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([]);

        $gate = new McpAccessGate($config, EnvironmentMode::Production, $env, '/tmp/project');

        $this->expectException(McpSecurityException::class);

        $gate->assertEnvironmentAllowed();
    }

    #[Test]
    public function concurrencyLimitEnforced(): void
    {
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([
            'max_concurrent_actions' => 1,
        ]);

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, '/tmp/project');

        $gate->assertConcurrencyAllowed();

        $this->expectException(McpSecurityException::class);

        $gate->assertConcurrencyAllowed();
    }

    #[Test]
    public function concurrencySlotRelease(): void
    {
        $env = Environment::load();
        $config = McpSecurityConfig::fromArray([
            'max_concurrent_actions' => 1,
        ]);

        $gate = new McpAccessGate($config, EnvironmentMode::Local, $env, '/tmp/project');

        $gate->assertConcurrencyAllowed();
        $gate->releaseConcurrencySlot();

        // After release, should be able to acquire again
        $gate->assertConcurrencyAllowed();

        $this->addToAssertionCount(1);
    }
}
