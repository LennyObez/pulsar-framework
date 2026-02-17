<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Admin\Command\AdminServeCommand;
use Pulsar\Extension\Admin\Config\AdminConfig;

#[CoversClass(AdminServeCommand::class)]
final class AdminServeCommandTest extends TestCase
{
    #[Test]
    public function execute_returns_error_when_disabled(): void
    {
        $config = AdminConfig::fromArray(['enabled' => false]);
        $command = new AdminServeCommand($config, '/tmp');

        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function execute_returns_error_when_router_script_missing(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $command = new AdminServeCommand($config, '/nonexistent/path');

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $input->method('getOption')->willReturn(null);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function command_name_and_description(): void
    {
        $config = AdminConfig::fromArray([]);
        $command = new AdminServeCommand($config, '/tmp');

        self::assertSame('admin:serve', $command->name);
        self::assertSame('Start the Admin panel development server', $command->description);
    }
}
