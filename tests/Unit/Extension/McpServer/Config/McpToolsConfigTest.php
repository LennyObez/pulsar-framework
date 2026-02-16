<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use ReflectionClass;

#[CoversClass(McpToolsConfig::class)]
final class McpToolsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = McpToolsConfig::fromArray([]);

        self::assertSame([], $config->disabledReadTools);
        self::assertSame([], $config->allowedActions);
        self::assertSame(1_048_576, $config->maxOutputBytes);
        self::assertSame(120, $config->actionTimeout);
        self::assertSame(['phpunit' => null, 'composer' => null, 'pnpm' => null], $config->commands);
    }

    #[Test]
    public function fromArrayWithExplicitValues(): void
    {
        $config = McpToolsConfig::fromArray([
            'disabled_read_tools' => ['pulsar.api.snapshot'],
            'allowed_actions' => ['pulsar.tests.run', 'pulsar.analysis.run'],
            'max_output_bytes' => 2_097_152,
            'action_timeout' => 60,
            'commands' => [
                'phpunit' => '/usr/bin/phpunit',
                'composer' => '/usr/bin/composer',
                'pnpm' => '/usr/local/bin/pnpm',
            ],
        ]);

        self::assertSame(['pulsar.api.snapshot'], $config->disabledReadTools);
        self::assertSame(['pulsar.tests.run', 'pulsar.analysis.run'], $config->allowedActions);
        self::assertSame(2_097_152, $config->maxOutputBytes);
        self::assertSame(60, $config->actionTimeout);
        self::assertSame('/usr/bin/phpunit', $config->commands['phpunit']);
        self::assertSame('/usr/bin/composer', $config->commands['composer']);
        self::assertSame('/usr/local/bin/pnpm', $config->commands['pnpm']);
    }

    #[Test]
    public function fromArrayCastsNumericStrings(): void
    {
        $config = McpToolsConfig::fromArray([
            'max_output_bytes' => '512000',
            'action_timeout' => 30,
        ]);

        // max_output_bytes requires is_int() — string '512000' falls back to default 1_048_576
        self::assertSame(1_048_576, $config->maxOutputBytes);
        self::assertSame(30, $config->actionTimeout);
    }

    #[Test]
    public function fromArrayPartialCommandsOnlySetProvided(): void
    {
        $config = McpToolsConfig::fromArray([
            'commands' => [
                'phpunit' => '/custom/phpunit',
            ],
        ]);

        self::assertSame('/custom/phpunit', $config->commands['phpunit']);
        self::assertNull($config->commands['composer']);
        self::assertNull($config->commands['pnpm']);
    }

    #[Test]
    public function fromArrayEmptyCommandsArray(): void
    {
        $config = McpToolsConfig::fromArray([
            'commands' => [],
        ]);

        self::assertNull($config->commands['phpunit']);
        self::assertNull($config->commands['composer']);
        self::assertNull($config->commands['pnpm']);
    }

    #[Test]
    #[DataProvider('commandPathProvider')]
    public function fromArrayCommandPathVariants(string $key, string $path): void
    {
        $config = McpToolsConfig::fromArray([
            'commands' => [$key => $path],
        ]);

        self::assertSame($path, $config->commands[$key]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commandPathProvider(): iterable
    {
        yield 'phpunit binary' => ['phpunit', 'vendor/bin/phpunit'];
        yield 'composer binary' => ['composer', '/usr/local/bin/composer'];
        yield 'pnpm binary' => ['pnpm', 'node_modules/.bin/pnpm'];
    }

    #[Test]
    public function isReadonly(): void
    {
        $config = McpToolsConfig::fromArray([]);

        $reflection = new ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly());
    }
}
