<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\McpServer\Config\McpConfig;

final class McpConfigTest extends TestCase
{
    private function createEnvironment(): Environment
    {
        // Use load() with no env file — picks up only OS vars
        return Environment::load();
    }

    #[Test]
    public function fromArrayBuildsFullConfig(): void
    {
        $config = McpConfig::fromArray([
            'enabled' => true,
            'client_id' => 'my-client',
            'project_root' => '/app',
            'tools' => ['max_output_bytes' => 2048],
            'security' => ['rate_limit_per_minute' => 30],
        ], $this->createEnvironment());

        self::assertTrue($config->enabled);
        self::assertSame('my-client', $config->clientId);
        self::assertSame('/app', $config->projectRoot);
        self::assertSame(2048, $config->tools->maxOutputBytes);
        self::assertSame(30, $config->security->rateLimitPerMinute);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = McpConfig::fromArray([], $this->createEnvironment());

        self::assertFalse($config->enabled);
        self::assertSame('', $config->projectRoot);
    }

    #[Test]
    public function fromArrayHandlesNonStringClientId(): void
    {
        $config = McpConfig::fromArray(['client_id' => 42], $this->createEnvironment());

        self::assertSame('default', $config->clientId);
    }
}
