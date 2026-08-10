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
use Pulsar\Console\Verbosity;
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

    #[Test]
    public function doRunSetsQuietVerbosity(): void
    {
        $input = new ArrayInput(null, [], ['quiet' => true]);
        $output = new BufferedOutput();

        $this->application->doRun($input, $output);

        self::assertSame(Verbosity::Quiet, $output->verbosity);
    }

    #[Test]
    public function doRunSetsVerboseVerbosity(): void
    {
        $input = new ArrayInput(null, [], ['v' => true]);
        $output = new BufferedOutput();

        $this->application->doRun($input, $output);

        self::assertSame(Verbosity::Verbose, $output->verbosity);
    }

    #[Test]
    public function doRunSetsDebugVerbosity(): void
    {
        $input = new ArrayInput(null, [], ['vvv' => true]);
        $output = new BufferedOutput();

        $this->application->doRun($input, $output);

        self::assertSame(Verbosity::Debug, $output->verbosity);
    }

    #[Test]
    public function doRunShowsHelpForSpecificCommand(): void
    {
        $command = $this->createCommandWithArgs('greet', 'Say hello');

        $this->application->add($command);

        $input = new ArrayInput('greet', [], ['help' => true]);
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        $content = $output->buffer;
        self::assertStringContainsString('Description:', $content);
        self::assertStringContainsString('Say hello', $content);
        self::assertStringContainsString('Usage:', $content);
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function doRunShowsVersionWithShortOption(): void
    {
        $input = new ArrayInput(null, [], ['V' => true]);
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        self::assertStringContainsString('Pulsar Framework', $output->buffer);
        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    #[Test]
    public function doRunShowsCommandExceptionTraceInVerboseMode(): void
    {
        $command = new class implements CommandInterface {
            public string $name = 'fail';
            public string $description = 'Fails';

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new RuntimeException('Verbose failure');
            }
        };

        $this->application->add($command);

        $input = new ArrayInput('fail', [], ['verbose' => true]);
        $output = new BufferedOutput();

        $exitCode = $this->application->doRun($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Verbose failure', $output->errorBuffer);
    }

    #[Test]
    public function doRunListsGroupedCommandsInHelp(): void
    {
        $this->application->add($this->createCommand('cache:clear', 'Clear cache'));
        $this->application->add($this->createCommand('cache:warmup', 'Warm cache'));
        $this->application->add($this->createCommand('migrate', 'Run migrations'));

        $input = new ArrayInput(null);
        $output = new BufferedOutput();

        $this->application->doRun($input, $output);

        $content = $output->buffer;
        self::assertStringContainsString('cache', $content);
        self::assertStringContainsString('cache:clear', $content);
        self::assertStringContainsString('migrate', $content);
    }

    /**
     * A command registered via `addLazy()` MUST not run
     * its factory at registration time. The first `get($name)`
     * triggers the factory and memoises the result; a second
     * `get()` returns the same instance without re-running it.
     */
    #[Test]
    public function addLazyDefersConstructionUntilGet(): void
    {
        $built = 0;
        $this->application->addLazy(
            'lazy:demo',
            'Demo lazy command',
            function () use (&$built): CommandInterface {
                $built++;
                return $this->createCommand('lazy:demo', 'Demo lazy command');
            },
        );

        // Registration alone must not build.
        self::assertSame(0, $built);
        self::assertTrue($this->application->has('lazy:demo'));

        // First get triggers the factory.
        $first = $this->application->get('lazy:demo');
        self::assertSame(1, $built);
        self::assertSame('lazy:demo', $first->name);

        // Second get must return the SAME memoised instance.
        $second = $this->application->get('lazy:demo');
        self::assertSame($first, $second);
        self::assertSame(1, $built, 'factory must not re-run on second get');
    }

    /**
     * Lazy commands appear in `allDescriptions()` for help
     * rendering without paying the materialisation cost — `all()`
     * stays restricted to materialised CommandInterface entries
     * so existing iterators (CoreRuntimeProbe) keep their typing
     * contract.
     */
    #[Test]
    public function allDescriptionsIncludesLazyButAllDoesNot(): void
    {
        $built = 0;
        $this->application->addLazy(
            'lazy:demo',
            'Demo lazy description',
            function () use (&$built): CommandInterface {
                $built++;
                return $this->createCommand('lazy:demo', 'Demo lazy description');
            },
        );

        $descriptions = $this->application->allDescriptions();
        self::assertArrayHasKey('lazy:demo', $descriptions);
        self::assertSame('Demo lazy description', $descriptions['lazy:demo']);

        // all() does not surface unmaterialised lazy entries.
        self::assertArrayNotHasKey('lazy:demo', $this->application->all());

        // Surfacing the description must not have built the
        // command.
        self::assertSame(0, $built);
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

    private function createCommandWithArgs(string $name, string $description): Command
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
                $this->addArgument('name', 'The name to greet', true);
                $this->addOption('shout', 'Shout the greeting', 's');
            }

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                return ExitCode::Success->value;
            }
        };
    }
}
