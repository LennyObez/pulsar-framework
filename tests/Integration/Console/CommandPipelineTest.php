<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Console;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\OutputInterface;
use Pulsar\Core\Kernel;
use RuntimeException;

use function is_string;
use function sprintf;

#[CoversClass(Application::class)]
#[CoversClass(Command::class)]
final class CommandPipelineTest extends TestCase
{
    private Application $app;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel();
        $this->app = new Application($this->kernel);
    }

    #[Test]
    public function commandRegistrationAndDispatch(): void
    {
        $this->app->add(new HelloWorldCommand());

        self::assertTrue($this->app->has('hello:world'));

        $input = new ArrayInput(commandName: 'hello:world');
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Hello, World!', $output->buffer);
    }

    #[Test]
    public function commandWithArgumentsReceivesInput(): void
    {
        $this->app->add(new GreetCommand());

        $input = new ArrayInput(commandName: 'greet', arguments: ['Alice']);
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Hello, Alice!', $output->buffer);
    }

    #[Test]
    public function commandWithOptionsReceivesOptions(): void
    {
        $this->app->add(new GreetCommand());

        $input = new ArrayInput(commandName: 'greet', arguments: ['Bob'], options: ['upper' => true]);
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('HELLO, BOB!', $output->buffer);
    }

    #[Test]
    public function unknownCommandReturnsInvalidExitCode(): void
    {
        $input = new ArrayInput(commandName: 'does:not:exist');
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Invalid->value, $code);
        self::assertStringContainsString('does:not:exist', $output->errorBuffer);
    }

    #[Test]
    public function commandThatThrowsReturnsErrorExitCode(): void
    {
        $this->app->add(new FailingCommand());

        $input = new ArrayInput(commandName: 'fail');
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Error->value, $code);
        self::assertStringContainsString('Error:', $output->errorBuffer);
    }

    #[Test]
    public function versionFlagOutputsVersionWithoutCommand(): void
    {
        $input = new ArrayInput(commandName: null, options: ['version' => true]);
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Pulsar Framework', $output->buffer);
    }

    #[Test]
    public function helpFlagWithNoCommandRendersApplicationHelp(): void
    {
        $this->app->add(new HelloWorldCommand());

        $input = new ArrayInput(commandName: null, options: ['help' => true]);
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Usage:', $output->buffer);
    }

    #[Test]
    public function addMultipleCommandsAndListAll(): void
    {
        $this->app->addCommands([new HelloWorldCommand(), new GreetCommand(), new FailingCommand()]);

        $all = $this->app->all();
        self::assertArrayHasKey('hello:world', $all);
        self::assertArrayHasKey('greet', $all);
        self::assertArrayHasKey('fail', $all);
    }

    #[Test]
    public function quietVerbositySuppressesOutput(): void
    {
        $this->app->add(new HelloWorldCommand());

        $input = new ArrayInput(commandName: 'hello:world', options: ['quiet' => true]);
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function helpFlagForSpecificCommandRendersCommandHelp(): void
    {
        $this->app->add(new GreetCommand());

        $input = new ArrayInput(commandName: 'greet', options: ['help' => true]);
        $output = new BufferedOutput();
        $code = $this->app->doRun($input, $output);

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Description:', $output->buffer);
    }

    #[Test]
    public function applicationKernelIsAccessible(): void
    {
        self::assertSame($this->kernel, $this->app->kernel());
    }
}

/**
 * @internal
 */
final class HelloWorldCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'hello:world';
        $this->description = 'Prints Hello, World!';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Hello, World!');
        return ExitCode::Success->value;
    }
}

/**
 * @internal
 */
final class GreetCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'greet';
        $this->description = 'Greet a person by name.';
        $this->addArgument('name', 'The name to greet', true);
        $this->addOption('upper', 'Uppercase the greeting', null, null);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument(0);
        $name = is_string($argument) ? $argument : '';
        $greeting = sprintf('Hello, %s!', $name);

        if ($input->hasOption('upper')) {
            $greeting = strtoupper($greeting);
        }

        $output->writeln($greeting);
        return ExitCode::Success->value;
    }
}

/**
 * @internal
 */
final class FailingCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'fail';
        $this->description = 'Always throws an exception.';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new RuntimeException('Command intentionally failed.');
    }
}
