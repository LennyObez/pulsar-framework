<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\CommandInterface;
use Pulsar\Console\Exception\CommandNotFoundException;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Kernel;
use RuntimeException;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    private Application $application;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel();
        $this->application = new Application($this->kernel);
    }

    #[Test]
    public function addRegistersCommand(): void
    {
        $command = $this->createCommand('test', 'Test command');

        $this->application->add($command);

        self::assertTrue($this->application->has('test'));
        self::assertSame($command, $this->application->get('test'));
    }

    #[Test]
    public function addCommandsRegistersMultiple(): void
    {
        $commands = [
            $this->createCommand('cmd1', 'Command 1'),
            $this->createCommand('cmd2', 'Command 2'),
        ];

        $this->application->addCommands($commands);

        self::assertTrue($this->application->has('cmd1'));
        self::assertTrue($this->application->has('cmd2'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownCommand(): void
    {
        self::assertFalse($this->application->has('nonexistent'));
    }

    #[Test]
    public function getThrowsForUnknownCommand(): void
    {
        $this->expectException(CommandNotFoundException::class);

        $_ = $this->application->get('nonexistent');
    }

    #[Test]
    public function allReturnsAllCommands(): void
    {
        $cmd1 = $this->createCommand('cmd1', 'Command 1');
        $cmd2 = $this->createCommand('cmd2', 'Command 2');

        $this->application->add($cmd1);
        $this->application->add($cmd2);

        $all = $this->application->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('cmd1', $all);
        self::assertArrayHasKey('cmd2', $all);
    }

    #[Test]
    public function doRunExecutesCommand(): void
    {
        $command = new class implements CommandInterface {
            public string $name = 'test';
            public string $description = 'Test';
            public bool $executed = false;

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->executed = true;
                return ExitCode::Success->value;
            }
        };

        $this->application->add($command);

        $input = new ArrayInput('test');
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        self::assertTrue($command->executed);
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function doRunShowsHelpWhenNoCommand(): void
    {
        $input = new ArrayInput(null);
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        $content = $output->buffer;
        self::assertStringContainsString('Pulsar Framework', $content);
        self::assertStringContainsString('Usage:', $content);
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function doRunShowsVersionWithVersionOption(): void
    {
        $input = new ArrayInput(null, [], ['version' => true]);
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        $content = $output->buffer;
        self::assertStringContainsString('Pulsar Framework', $content);
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function doRunReturnsErrorForUnknownCommand(): void
    {
        $input = new ArrayInput('nonexistent');
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function doRunHandlesCommandException(): void
    {
        $command = new class implements CommandInterface {
            public string $name = 'test';
            public string $description = 'Test';

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new RuntimeException('Test error');
            }
        };

        $this->application->add($command);

        $input = new ArrayInput('test');
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Test error', $output->errorBuffer);
    }

    #[Test]
    public function kernelReturnsKernelInstance(): void
    {
        self::assertSame($this->kernel, $this->application->kernel());
    }

    private function createCommand(string $name, string $description): CommandInterface
    {
        return new class ($name, $description) extends Command {
            public function __construct(
                private readonly string $commandName,
                private readonly string $commandDescription,
            ) {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->name = $this->commandName;
                $this->description = $this->commandDescription;
            }

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                return ExitCode::Success->value;
            }
        };
    }
}
