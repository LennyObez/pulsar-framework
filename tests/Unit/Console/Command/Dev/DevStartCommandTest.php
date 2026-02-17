<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Dev\DevConfig;
use Pulsar\Console\Command\Dev\DevStartCommand;

#[CoversClass(DevStartCommand::class)]
final class DevStartCommandTest extends TestCase
{
    #[Test]
    public function commandNameAndDescription(): void
    {
        $command = new DevStartCommand(new DevConfig(), sys_get_temp_dir());

        self::assertSame('dev:start', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function commandHasExpectedOptions(): void
    {
        $command = new DevStartCommand(new DevConfig(), sys_get_temp_dir());

        self::assertArrayHasKey('database', $command->options);
        self::assertArrayHasKey('no-redis', $command->options);
        self::assertArrayHasKey('no-mailpit', $command->options);
        self::assertArrayHasKey('port', $command->options);
        self::assertArrayHasKey('rebuild', $command->options);
    }

    #[Test]
    public function commandUsageIncludesName(): void
    {
        $command = new DevStartCommand(new DevConfig(), sys_get_temp_dir());

        self::assertStringStartsWith('dev:start', $command->getUsage());
    }
}
