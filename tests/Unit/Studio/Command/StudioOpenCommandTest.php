<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Command\StudioOpenCommand;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Config\StudioServerConfig;

#[CoversClass(StudioOpenCommand::class)]
final class StudioOpenCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $config = $this->createConfig();
        $command = new StudioOpenCommand($config);

        self::assertSame('studio:open', $command->name);
        self::assertSame('Open Studio in the default browser', $command->description);
        self::assertEmpty($command->options);
        self::assertEmpty($command->arguments);
    }

    #[Test]
    public function hasNoOptions(): void
    {
        $config = $this->createConfig();
        $command = new StudioOpenCommand($config);

        self::assertEmpty($command->options);
    }

    #[Test]
    public function hasNoArguments(): void
    {
        $config = $this->createConfig();
        $command = new StudioOpenCommand($config);

        self::assertEmpty($command->arguments);
    }

    #[Test]
    public function usageMatchesCommandName(): void
    {
        $config = $this->createConfig();
        $command = new StudioOpenCommand($config);

        self::assertSame('studio:open', $command->getUsage());
    }

    #[Test]
    public function constructsWithConfig(): void
    {
        $config = $this->createConfig(host: 'localhost', port: 9000);
        $command = new StudioOpenCommand($config);

        // Command should be constructed successfully
        self::assertInstanceOf(StudioOpenCommand::class, $command);
    }

    #[Test]
    public function acceptsDifferentHostConfigurations(): void
    {
        $configs = [
            $this->createConfig(host: '127.0.0.1', port: 8585),
            $this->createConfig(host: 'localhost', port: 3000),
            $this->createConfig(host: '0.0.0.0', port: 9000),
        ];

        foreach ($configs as $config) {
            $command = new StudioOpenCommand($config);
            self::assertInstanceOf(StudioOpenCommand::class, $command);
        }
    }

    private function createConfig(
        string $host = '127.0.0.1',
        int $port = 8585,
    ): StudioConfig {
        return new StudioConfig(
            enabled: true,
            storagePath: 'storage/studio/studio.sqlite',
            retention: new StudioRetentionConfig(),
            security: new StudioSecurityConfig(),
            server: new StudioServerConfig(host: $host, port: $port),
            collectors: new StudioCollectorConfig(),
        );
    }
}
