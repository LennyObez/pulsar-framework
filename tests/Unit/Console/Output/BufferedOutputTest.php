<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Console\Verbosity;

#[CoversClass(BufferedOutput::class)]
final class BufferedOutputTest extends TestCase
{
    #[Test]
    public function writeBuffersContent(): void
    {
        $output = new BufferedOutput();

        $output->write('Hello');
        $output->write(' World');

        self::assertSame('Hello World', $output->buffer);
    }

    #[Test]
    public function writelnAppendsNewline(): void
    {
        $output = new BufferedOutput();

        $output->writeln('Line 1');

        self::assertSame('Line 1' . PHP_EOL, $output->buffer);
    }

    #[Test]
    public function errorBuffersToErrorBuffer(): void
    {
        $output = new BufferedOutput();

        $output->error('Something went wrong');

        self::assertSame('Something went wrong', $output->errorBuffer);
        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function errorlnAppendsNewline(): void
    {
        $output = new BufferedOutput();

        $output->errorln('Error occurred');

        self::assertSame('Error occurred' . PHP_EOL, $output->errorBuffer);
    }

    #[Test]
    public function successWritesWithPrefix(): void
    {
        $output = new BufferedOutput();

        $output->success('Deployment complete');

        self::assertStringContainsString('[SUCCESS]', $output->buffer);
        self::assertStringContainsString('Deployment complete', $output->buffer);
    }

    #[Test]
    public function infoWritesWithPrefix(): void
    {
        $output = new BufferedOutput();

        $output->info('Processing 42 records');

        self::assertStringContainsString('[INFO]', $output->buffer);
        self::assertStringContainsString('Processing 42 records', $output->buffer);
    }

    #[Test]
    public function warningWritesWithPrefix(): void
    {
        $output = new BufferedOutput();

        $output->warning('Deprecated API endpoint');

        self::assertStringContainsString('[WARNING]', $output->buffer);
        self::assertStringContainsString('Deprecated API endpoint', $output->buffer);
    }

    #[Test]
    public function quietModeSuppressesOutput(): void
    {
        $output = new BufferedOutput(Verbosity::Quiet);

        $output->write('Should not appear');
        $output->error('Should not appear either');

        self::assertSame('', $output->buffer);
        self::assertSame('', $output->errorBuffer);
    }

    #[Test]
    public function isQuietReturnsCorrectly(): void
    {
        self::assertTrue(new BufferedOutput(Verbosity::Quiet)->isQuiet());
        self::assertFalse(new BufferedOutput(Verbosity::Normal)->isQuiet());
    }

    #[Test]
    public function isVerboseReturnsCorrectly(): void
    {
        self::assertFalse(new BufferedOutput(Verbosity::Normal)->isVerbose());
        self::assertTrue(new BufferedOutput(Verbosity::Verbose)->isVerbose());
    }

    #[Test]
    public function isDebugReturnsCorrectly(): void
    {
        self::assertFalse(new BufferedOutput(Verbosity::Verbose)->isDebug());
        self::assertTrue(new BufferedOutput(Verbosity::Debug)->isDebug());
    }

    #[Test]
    public function newLineWritesEmptyLines(): void
    {
        $output = new BufferedOutput();

        $output->newLine(3);

        self::assertSame(str_repeat(PHP_EOL, 3), $output->buffer);
    }

    #[Test]
    public function fetchReturnsAndClearsBuffer(): void
    {
        $output = new BufferedOutput();
        $output->write('content');

        $fetched = $output->fetch();

        self::assertSame('content', $fetched);
        self::assertSame('', $output->buffer);
    }

    #[Test]
    public function fetchErrorReturnsAndClearsErrorBuffer(): void
    {
        $output = new BufferedOutput();
        $output->error('error content');

        $fetched = $output->fetchError();

        self::assertSame('error content', $fetched);
        self::assertSame('', $output->errorBuffer);
    }

    #[Test]
    public function clearResetsAllBuffers(): void
    {
        $output = new BufferedOutput();
        $output->write('standard');
        $output->error('error');

        $output->clear();

        self::assertSame('', $output->buffer);
        self::assertSame('', $output->errorBuffer);
    }
}
