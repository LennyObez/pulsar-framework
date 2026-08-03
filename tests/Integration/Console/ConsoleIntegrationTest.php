<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Console;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\Exception\CommandNotFoundException;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\OutputInterface;
use Pulsar\Console\Verbosity;
use Pulsar\Core\Kernel;
use RuntimeException;

use function is_string;
use function sprintf;
use function strtoupper;

#[CoversClass(Application::class)]
#[CoversClass(Command::class)]
#[CoversClass(ArrayInput::class)]
#[CoversClass(BufferedOutput::class)]
#[CoversClass(ExitCode::class)]
#[CoversClass(Verbosity::class)]
#[CoversClass(CommandNotFoundException::class)]
final class ConsoleIntegrationTest extends TestCase
{
    private Application $app;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->app = new Application(new Kernel());
        $this->output = new BufferedOutput();
    }

    // ---------------------------------------------------------------
    // Command registration
    // ---------------------------------------------------------------

    #[Test]
    public function registerAndRetrieveCommandByName(): void
    {
        $this->app->add(new EchoCommand());

        self::assertTrue($this->app->has('echo'));
        $command = $this->app->get('echo');
        self::assertSame('echo', $command->name);
        self::assertSame('Echo input back to output.', $command->description);
    }

    #[Test]
    public function registerMultipleCommandsAtOnce(): void
    {
        $this->app->addCommands([
            new EchoCommand(),
            new MathAddCommand(),
            new FormatDemoCommand(),
        ]);

        self::assertTrue($this->app->has('echo'));
        self::assertTrue($this->app->has('math:add'));
        self::assertTrue($this->app->has('format:demo'));
    }

    #[Test]
    public function getThrowsCommandNotFoundForUnknown(): void
    {
        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('nonexistent');

        (void) $this->app->get('nonexistent');
    }

    #[Test]
    public function commandNotFoundIncludesAlternatives(): void
    {
        $this->app->add(new EchoCommand());

        try {
            (void) $this->app->get('ech');
            self::fail('Expected CommandNotFoundException');
        } catch (CommandNotFoundException $e) {
            self::assertStringContainsString('echo', $e->getMessage());
        }
    }

    #[Test]
    public function allReturnsEveryRegisteredCommand(): void
    {
        $this->app->add(new EchoCommand());
        $this->app->add(new MathAddCommand());

        $all = $this->app->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('echo', $all);
        self::assertArrayHasKey('math:add', $all);
    }

    // ---------------------------------------------------------------
    // Argument parsing
    // ---------------------------------------------------------------

    #[Test]
    public function commandReceivesSinglePositionalArgument(): void
    {
        $this->app->add(new EchoCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'echo', arguments: ['hello']),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('hello', $this->output->buffer);
    }

    #[Test]
    public function commandReceivesMultiplePositionalArguments(): void
    {
        $this->app->add(new MathAddCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'math:add', arguments: ['3', '7']),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('10', $this->output->buffer);
    }

    #[Test]
    public function missingRequiredArgumentUsesDefaultGracefully(): void
    {
        $this->app->add(new EchoCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'echo'),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('(empty)', $this->output->buffer);
    }

    // ---------------------------------------------------------------
    // Option handling
    // ---------------------------------------------------------------

    #[Test]
    public function booleanOptionIsDetected(): void
    {
        $this->app->add(new EchoCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'echo', arguments: ['world'], options: ['upper' => true]),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('WORLD', $this->output->buffer);
    }

    #[Test]
    public function valuedOptionIsParsed(): void
    {
        $this->app->add(new EchoCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'echo', arguments: ['hi'], options: ['repeat' => '3']),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);

        // "hi" repeated 3 times
        $buffer = $this->output->buffer;
        self::assertSame(3, substr_count($buffer, 'hi'));
    }

    #[Test]
    public function arrayInputTokensReflectState(): void
    {
        $input = new ArrayInput(
            commandName: 'deploy',
            arguments: ['production'],
            options: ['force' => true, 'timeout' => '30'],
        );

        $tokens = $input->tokens;

        self::assertContains('deploy', $tokens);
        self::assertContains('production', $tokens);
        self::assertContains('--force', $tokens);
        self::assertContains('--timeout=30', $tokens);
    }

    // ---------------------------------------------------------------
    // Output formatting
    // ---------------------------------------------------------------

    #[Test]
    public function successOutputIsFormatted(): void
    {
        $this->app->add(new FormatDemoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'format:demo', options: ['type' => 'success']),
            $this->output,
        );

        self::assertStringContainsString('[SUCCESS]', $this->output->buffer);
    }

    #[Test]
    public function infoOutputIsFormatted(): void
    {
        $this->app->add(new FormatDemoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'format:demo', options: ['type' => 'info']),
            $this->output,
        );

        self::assertStringContainsString('[INFO]', $this->output->buffer);
    }

    #[Test]
    public function warningOutputIsFormatted(): void
    {
        $this->app->add(new FormatDemoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'format:demo', options: ['type' => 'warning']),
            $this->output,
        );

        self::assertStringContainsString('[WARNING]', $this->output->buffer);
    }

    #[Test]
    public function errorOutputGoesToErrorBuffer(): void
    {
        $this->app->add(new FormatDemoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'format:demo', options: ['type' => 'error']),
            $this->output,
        );

        self::assertStringContainsString('Error occurred', $this->output->errorBuffer);
    }

    // ---------------------------------------------------------------
    // Verbosity levels
    // ---------------------------------------------------------------

    #[Test]
    public function quietModeSupressesOutput(): void
    {
        $this->app->add(new EchoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'echo', arguments: ['test'], options: ['quiet' => true]),
            $this->output,
        );

        self::assertSame('', $this->output->buffer);
        self::assertTrue($this->output->isQuiet());
    }

    #[Test]
    public function verboseModeIsDetected(): void
    {
        $this->app->add(new FormatDemoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'format:demo', options: ['type' => 'info', 'verbose' => true]),
            $this->output,
        );

        self::assertTrue($this->output->isVerbose());
    }

    #[Test]
    public function debugModeIsDetected(): void
    {
        $this->app->add(new FormatDemoCommand());

        $this->app->doRun(
            new ArrayInput(commandName: 'format:demo', options: ['type' => 'info', 'vvv' => true]),
            $this->output,
        );

        self::assertTrue($this->output->isDebug());
    }

    // ---------------------------------------------------------------
    // Error handling
    // ---------------------------------------------------------------

    #[Test]
    public function commandExceptionReturnsErrorCode(): void
    {
        $this->app->add(new ThrowingCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'throw'),
            $this->output,
        );

        self::assertSame(ExitCode::Error->value, $code);
        self::assertStringContainsString('Intentional explosion', $this->output->errorBuffer);
    }

    #[Test]
    public function unknownCommandReturnsInvalidExitCode(): void
    {
        $code = $this->app->doRun(
            new ArrayInput(commandName: 'phantom'),
            $this->output,
        );

        self::assertSame(ExitCode::Invalid->value, $code);
    }

    // ---------------------------------------------------------------
    // Global flags
    // ---------------------------------------------------------------

    #[Test]
    public function versionFlagShowsFrameworkVersion(): void
    {
        $code = $this->app->doRun(
            new ArrayInput(commandName: null, options: ['version' => true]),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Pulsar Framework', $this->output->buffer);
    }

    #[Test]
    public function helpFlagRendersUsageInformation(): void
    {
        $this->app->add(new EchoCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: null, options: ['help' => true]),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Usage:', $this->output->buffer);
        self::assertStringContainsString('Available commands:', $this->output->buffer);
    }

    #[Test]
    public function helpFlagForSpecificCommandShowsCommandHelp(): void
    {
        $this->app->add(new EchoCommand());

        $code = $this->app->doRun(
            new ArrayInput(commandName: 'echo', options: ['help' => true]),
            $this->output,
        );

        self::assertSame(ExitCode::Success->value, $code);
        self::assertStringContainsString('Description:', $this->output->buffer);
        self::assertStringContainsString('Echo input back to output.', $this->output->buffer);
    }

    // ---------------------------------------------------------------
    // BufferedOutput fetch/clear
    // ---------------------------------------------------------------

    #[Test]
    public function bufferedOutputFetchClearsBuffer(): void
    {
        $this->output->writeln('line one');
        $fetched = $this->output->fetch();

        self::assertStringContainsString('line one', $fetched);
        self::assertSame('', $this->output->buffer);
    }

    #[Test]
    public function bufferedOutputClearResetsEverything(): void
    {
        $this->output->writeln('stdout');
        $this->output->errorln('stderr');
        $this->output->clear();

        self::assertSame('', $this->output->buffer);
        self::assertSame('', $this->output->errorBuffer);
    }

    // ---------------------------------------------------------------
    // Command usage string
    // ---------------------------------------------------------------

    #[Test]
    public function commandUsageStringIncludesOptionsAndArguments(): void
    {
        $cmd = new EchoCommand();

        $usage = $cmd->getUsage();

        self::assertStringContainsString('echo', $usage);
        self::assertStringContainsString('--upper', $usage);
        self::assertStringContainsString('<text>', $usage);
    }
}

// ===================================================================
// Test command fixtures
// ===================================================================

/** @internal */
final class EchoCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'echo';
        $this->description = 'Echo input back to output.';
        $this->addArgument('text', 'The text to echo', true);
        $this->addOption('upper', 'Uppercase the text', 'u');
        $this->addOption('repeat', 'How many times to repeat', 'r', '1');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $text = $input->getArgument(0);
        $text = is_string($text) ? $text : '(empty)';

        if ($input->hasOption('upper')) {
            $text = strtoupper($text);
        }

        $repeatValue = $input->getOption('repeat', '1');
        $repeat = is_numeric($repeatValue) ? (int) $repeatValue : 1;

        for ($i = 0; $i < $repeat; $i++) {
            $output->writeln($text);
        }

        return ExitCode::Success->value;
    }
}

/** @internal */
final class MathAddCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'math:add';
        $this->description = 'Add two numbers.';
        $this->addArgument('a', 'First number', true);
        $this->addArgument('b', 'Second number', true);
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $aValue = $input->getArgument(0);
        $a = is_numeric($aValue) ? (int) $aValue : 0;
        $bValue = $input->getArgument(1);
        $b = is_numeric($bValue) ? (int) $bValue : 0;

        $output->writeln(sprintf('Result: %d', $a + $b));

        return ExitCode::Success->value;
    }
}

/** @internal */
final class FormatDemoCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'format:demo';
        $this->description = 'Demonstrate output formatting.';
        $this->addOption('type', 'Output type (success|info|warning|error)', 't', 'info');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getOption('type', 'info');

        match ($type) {
            'success' => $output->success('Operation completed'),
            'warning' => $output->warning('Proceed with caution'),
            'error' => $output->errorln('Error occurred'),
            default => $output->info('General information'),
        };

        return ExitCode::Success->value;
    }
}

/** @internal */
final class ThrowingCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->name = 'throw';
        $this->description = 'Always throws.';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new RuntimeException('Intentional explosion');
    }
}
