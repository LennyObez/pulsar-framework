<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(Command::class)]
final class CommandTest extends TestCase
{
    #[Test]
    public function configureIsCalledInConstructor(): void
    {
        $command = new TestCommand();

        self::assertSame('test:command', $command->name);
        self::assertSame('A test command', $command->description);
    }

    #[Test]
    public function argumentsPropertyReturnsDefinedArguments(): void
    {
        $command = new TestCommand();
        $arguments = $command->arguments;

        self::assertCount(2, $arguments);
        self::assertSame('name', $arguments[0]['name']);
        self::assertTrue($arguments[0]['required']);
        self::assertSame('option', $arguments[1]['name']);
        self::assertFalse($arguments[1]['required']);
    }

    #[Test]
    public function optionsPropertyReturnsDefinedOptions(): void
    {
        $command = new TestCommand();
        $options = $command->options;

        self::assertArrayHasKey('verbose', $options);
        self::assertSame('v', $options['verbose']['shortcut']);
        self::assertArrayHasKey('format', $options);
        self::assertSame('text', $options['format']['default']);
    }

    #[Test]
    public function getUsageReturnsFormattedUsage(): void
    {
        $command = new TestCommand();
        $usage = $command->getUsage();

        self::assertStringContainsString('test:command', $usage);
        self::assertStringContainsString('<name>', $usage);
        self::assertStringContainsString('[option]', $usage);
        self::assertStringContainsString('--verbose', $usage);
        self::assertStringContainsString('--format', $usage);
    }
}

class TestCommand extends Command
{
    protected function configure(): void
    {
        $this->name = 'test:command';
        $this->description = 'A test command';
        $this->addArgument('name', 'The name argument', true);
        $this->addArgument('option', 'An optional argument', false);
        $this->addOption('verbose', 'Verbose output', 'v');
        $this->addOption('format', 'Output format', 'f', 'text');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        return ExitCode::Success->value;
    }
}
