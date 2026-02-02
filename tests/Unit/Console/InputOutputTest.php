<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Input\ArgvInput;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\Verbosity;

#[CoversClass(ArgvInput::class)]
#[CoversClass(ArrayInput::class)]
#[CoversClass(BufferedOutput::class)]
final class InputOutputTest extends TestCase
{
    #[Test]
    public function argvInputParsesCommandName(): void
    {
        $input = new ArgvInput(['pulsar', 'test']);

        self::assertSame('test', $input->getCommandName());
    }

    #[Test]
    public function argvInputParsesArguments(): void
    {
        $input = new ArgvInput(['pulsar', 'test', 'arg1', 'arg2']);

        self::assertSame('arg1', $input->getArgument(0));
        self::assertSame('arg2', $input->getArgument(1));
    }

    #[Test]
    public function argvInputParsesLongOptions(): void
    {
        $input = new ArgvInput(['pulsar', 'test', '--verbose', '--name=value']);

        self::assertTrue($input->hasOption('verbose'));
        self::assertTrue($input->getOption('verbose'));
        self::assertSame('value', $input->getOption('name'));
    }

    #[Test]
    public function argvInputParsesShortOptions(): void
    {
        $input = new ArgvInput(['pulsar', 'test', '-v', '-n=value']);

        self::assertTrue($input->hasOption('v'));
        self::assertSame('value', $input->getOption('n'));
    }

    #[Test]
    public function argvInputReturnDefaultForMissingOption(): void
    {
        $input = new ArgvInput(['pulsar', 'test']);

        self::assertSame('default', $input->getOption('missing', 'default'));
    }

    #[Test]
    public function argvInputGetTokensReturnsAllTokens(): void
    {
        $input = new ArgvInput(['pulsar', 'test', '--opt', 'arg']);

        self::assertSame(['test', '--opt', 'arg'], $input->getTokens());
    }

    #[Test]
    public function arrayInputReturnsCommandName(): void
    {
        $input = new ArrayInput('test');

        self::assertSame('test', $input->getCommandName());
    }

    #[Test]
    public function arrayInputReturnsArguments(): void
    {
        $input = new ArrayInput('test', ['arg1', 'arg2']);

        self::assertSame('arg1', $input->getArgument(0));
        self::assertSame('arg2', $input->getArgument(1));
        self::assertSame(['arg1', 'arg2'], $input->getArguments());
    }

    #[Test]
    public function arrayInputReturnsOptions(): void
    {
        $input = new ArrayInput('test', [], ['verbose' => true, 'name' => 'value']);

        self::assertTrue($input->hasOption('verbose'));
        self::assertTrue($input->getOption('verbose'));
        self::assertSame('value', $input->getOption('name'));
    }

    #[Test]
    public function arrayInputGetTokensReconstructsTokens(): void
    {
        $input = new ArrayInput('test', ['arg1'], ['verbose' => true, 'name' => 'value']);

        $tokens = $input->getTokens();

        self::assertContains('test', $tokens);
        self::assertContains('arg1', $tokens);
        self::assertContains('--verbose', $tokens);
        self::assertContains('--name=value', $tokens);
    }

    #[Test]
    public function bufferedOutputWritesContent(): void
    {
        $output = new BufferedOutput();

        $output->write('Hello');
        $output->write(' World');

        self::assertSame('Hello World', $output->getBuffer());
    }

    #[Test]
    public function bufferedOutputWritelnAddsNewline(): void
    {
        $output = new BufferedOutput();

        $output->writeln('Line 1');
        $output->writeln('Line 2');

        self::assertSame('Line 1' . PHP_EOL . 'Line 2' . PHP_EOL, $output->getBuffer());
    }

    #[Test]
    public function bufferedOutputErrorWritesToErrorBuffer(): void
    {
        $output = new BufferedOutput();

        $output->error('Error message');

        self::assertSame('Error message', $output->getErrorBuffer());
        self::assertSame('', $output->getBuffer());
    }

    #[Test]
    public function bufferedOutputFetchClearsBuffer(): void
    {
        $output = new BufferedOutput();

        $output->write('Content');
        $fetched = $output->fetch();

        self::assertSame('Content', $fetched);
        self::assertSame('', $output->getBuffer());
    }

    #[Test]
    public function bufferedOutputClearEmptiesAllBuffers(): void
    {
        $output = new BufferedOutput();

        $output->write('Content');
        $output->error('Error');
        $output->clear();

        self::assertSame('', $output->getBuffer());
        self::assertSame('', $output->getErrorBuffer());
    }

    #[Test]
    public function bufferedOutputRespectsVerbosity(): void
    {
        $output = new BufferedOutput(Verbosity::Quiet);

        $output->write('Content');

        self::assertSame('', $output->getBuffer());
    }

    #[Test]
    public function bufferedOutputNewLineWritesNewlines(): void
    {
        $output = new BufferedOutput();

        $output->newLine(3);

        self::assertSame(PHP_EOL . PHP_EOL . PHP_EOL, $output->getBuffer());
    }

    #[Test]
    public function bufferedOutputVerbosityMethods(): void
    {
        $quiet = new BufferedOutput(Verbosity::Quiet);
        $normal = new BufferedOutput(Verbosity::Normal);
        $verbose = new BufferedOutput(Verbosity::Verbose);
        $debug = new BufferedOutput(Verbosity::Debug);

        self::assertTrue($quiet->isQuiet());
        self::assertFalse($normal->isQuiet());

        self::assertFalse($normal->isVerbose());
        self::assertTrue($verbose->isVerbose());

        self::assertFalse($verbose->isDebug());
        self::assertTrue($debug->isDebug());
    }

    #[Test]
    public function bufferedOutputSetVerbosity(): void
    {
        $output = new BufferedOutput(Verbosity::Normal);

        self::assertSame(Verbosity::Normal, $output->getVerbosity());

        $output->setVerbosity(Verbosity::Debug);
        self::assertSame(Verbosity::Debug, $output->getVerbosity());
    }

    #[Test]
    public function bufferedOutputSuccessInfoWarning(): void
    {
        $output = new BufferedOutput();

        $output->success('Success message');
        $output->info('Info message');
        $output->warning('Warning message');

        $buffer = $output->getBuffer();

        self::assertStringContainsString('Success message', $buffer);
        self::assertStringContainsString('Info message', $buffer);
        self::assertStringContainsString('Warning message', $buffer);
    }
}
