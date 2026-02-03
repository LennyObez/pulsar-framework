<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Output;

use function is_resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Output\ConsoleOutput;
use Pulsar\Console\Verbosity;

#[CoversClass(ConsoleOutput::class)]
final class ConsoleOutputTest extends TestCase
{
    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }

        if (is_resource($this->stderr)) {
            fclose($this->stderr);
        }
    }

    #[Test]
    public function writesSToStream(): void
    {
        $output = new ConsoleOutput($this->stdout, $this->stderr, decorated: false);
        $output->write('Hello');
        $output->writeln(' World');

        rewind($this->stdout);
        $content = stream_get_contents($this->stdout);

        self::assertSame('Hello World' . PHP_EOL, $content);
    }

    #[Test]
    public function errorWritesToStderr(): void
    {
        $output = new ConsoleOutput($this->stdout, $this->stderr, decorated: false);
        $output->error('Error!');
        $output->errorln('Error line');

        rewind($this->stderr);
        $content = stream_get_contents($this->stderr);

        self::assertIsString($content);
        self::assertStringContainsString('Error!', $content);
        self::assertStringContainsString('Error line', $content);
    }

    #[Test]
    public function quietSuppressesOutput(): void
    {
        $output = new ConsoleOutput($this->stdout, $this->stderr, Verbosity::Quiet, decorated: false);
        $output->write('Nothing');
        $output->error('Nothing');

        rewind($this->stdout);
        rewind($this->stderr);
        self::assertSame('', stream_get_contents($this->stdout));
        self::assertSame('', stream_get_contents($this->stderr));
    }

    #[Test]
    public function successInfoWarning(): void
    {
        $output = new ConsoleOutput($this->stdout, $this->stderr, decorated: false);
        $output->success('OK');
        $output->info('Info');
        $output->warning('Warn');

        rewind($this->stdout);
        $content = stream_get_contents($this->stdout);

        self::assertIsString($content);
        self::assertStringContainsString('OK', $content);
        self::assertStringContainsString('Info', $content);
        self::assertStringContainsString('Warn', $content);
    }

    #[Test]
    public function verbosityMethods(): void
    {
        $quiet = new ConsoleOutput($this->stdout, $this->stderr, Verbosity::Quiet, decorated: false);
        self::assertTrue($quiet->isQuiet());
        self::assertFalse($quiet->isVerbose());

        // Create new streams for each instance
        fclose($this->stdout);
        fclose($this->stderr);

        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $verbose = new ConsoleOutput($out, $err, Verbosity::Verbose, decorated: false);
        self::assertTrue($verbose->isVerbose());
        self::assertFalse($verbose->isDebug());

        fclose($out);
        fclose($err);

        $out2 = fopen('php://memory', 'r+');
        $err2 = fopen('php://memory', 'r+');
        self::assertIsResource($out2);
        self::assertIsResource($err2);

        $debug = new ConsoleOutput($out2, $err2, Verbosity::Debug, decorated: false);
        self::assertTrue($debug->isDebug());

        // Re-assign for tearDown
        $this->stdout = $out2;
        $this->stderr = $err2;
    }

    #[Test]
    public function newLineWritesMultiple(): void
    {
        $output = new ConsoleOutput($this->stdout, $this->stderr, decorated: false);
        $output->newLine(2);

        rewind($this->stdout);
        self::assertSame(PHP_EOL . PHP_EOL, stream_get_contents($this->stdout));
    }

    #[Test]
    public function decoratedFormatting(): void
    {
        $output = new ConsoleOutput($this->stdout, $this->stderr, decorated: true);
        $output->error('Red error');

        rewind($this->stderr);
        $content = stream_get_contents($this->stderr);

        self::assertIsString($content);
        // Should contain ANSI escape codes
        self::assertStringContainsString("\033[31m", $content);
        self::assertStringContainsString('Red error', $content);
    }
}
