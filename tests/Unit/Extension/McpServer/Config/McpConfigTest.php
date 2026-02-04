<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;

#[CoversClass(McpConfig::class)]
#[CoversClass(McpToolsConfig::class)]
#[CoversClass(McpSecurityConfig::class)]
final class McpConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('MCP_ENABLED');
        putenv('MCP_CLIENT_ID');
    }

    protected function tearDown(): void
    {
        putenv('MCP_ENABLED');
        putenv('MCP_CLIENT_ID');
    }

    #[Test]
    public function fromArrayDefaultsAreCorrect(): void
    {
        $env = Environment::load();

        $config = McpConfig::fromArray([], $env);

        self::assertFalse($config->enabled);
        self::assertSame('default', $config->clientId);
        self::assertSame('', $config->projectRoot);
        self::assertSame([], $config->tools->disabledReadTools);
        self::assertSame([], $config->tools->allowedActions);
        self::assertSame(1_048_576, $config->tools->maxOutputBytes);
        self::assertSame(120, $config->tools->actionTimeout);
        self::assertSame([], $config->security->pathAllowlist);
        self::assertSame(60, $config->security->rateLimitPerMinute);
        self::assertSame(1, $config->security->maxConcurrentActions);
    }

    #[Test]
    public function fromArrayEnvOverridesEnabled(): void
    {
        putenv('MCP_ENABLED=true');

        $env = Environment::load();

        $config = McpConfig::fromArray(['enabled' => false], $env);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayEnvOverridesClientId(): void
    {
        putenv('MCP_CLIENT_ID=custom-client');

        $env = Environment::load();

        $config = McpConfig::fromArray(['client_id' => 'original'], $env);

        self::assertSame('custom-client', $config->clientId);
    }
}
