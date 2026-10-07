<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;

final class McpToolsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayBuildsConfig(): void
    {
        $config = McpToolsConfig::fromArray([
            'disabled_read_tools' => ['read_routes'],
            'allowed_actions' => ['run_tests', 'run_formatter'],
            'max_output_bytes' => 512_000,
            'action_timeout' => 60,
            'commands' => [
                'phpunit' => '/usr/bin/phpunit',
                'composer' => '/usr/bin/composer',
                'pnpm' => '/usr/bin/pnpm',
            ],
        ]);

        self::assertSame(['read_routes'], $config->disabledReadTools);
        self::assertSame(['run_tests', 'run_formatter'], $config->allowedActions);
        self::assertSame(512_000, $config->maxOutputBytes);
        self::assertSame(60, $config->actionTimeout);
        self::assertSame('/usr/bin/phpunit', $config->commands['phpunit']);
        self::assertSame('/usr/bin/composer', $config->commands['composer']);
        self::assertSame('/usr/bin/pnpm', $config->commands['pnpm']);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = McpToolsConfig::fromArray([]);

        self::assertSame([], $config->disabledReadTools);
        self::assertSame([], $config->allowedActions);
        self::assertSame(1_048_576, $config->maxOutputBytes);
        self::assertSame(120, $config->actionTimeout);
        self::assertNull($config->commands['phpunit']);
        self::assertNull($config->commands['composer']);
        self::assertNull($config->commands['pnpm']);
    }

    #[Test]
    public function fromArrayHandlesNonIntMaxOutput(): void
    {
        $config = McpToolsConfig::fromArray(['max_output_bytes' => 'big']);

        self::assertSame(1_048_576, $config->maxOutputBytes);
    }
}
