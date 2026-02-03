<?php

declare(strict_types=1);

namespace Pulsar\Console\Output;

use Pulsar\Console\OutputInterface;
use Pulsar\Console\Verbosity;

/**
 * Output that buffers content (useful for testing).
 */
final class BufferedOutput implements OutputInterface
{
    private string $buffer = '';
    private string $errorBuffer = '';
    private Verbosity $verbosity;

    public function __construct(Verbosity $verbosity = Verbosity::Normal)
    {
        $this->verbosity = $verbosity;
    }

    public function write(string $message): void
    {
        if ($this->verbosity === Verbosity::Quiet) {
            return;
        }

        $this->buffer .= $message;
    }

    public function writeln(string $message = ''): void
    {
        $this->write($message . PHP_EOL);
    }

    public function error(string $message): void
    {
        if ($this->verbosity === Verbosity::Quiet) {
            return;
        }

        $this->errorBuffer .= $message;
    }

    public function errorln(string $message = ''): void
    {
        $this->error($message . PHP_EOL);
    }

    public function success(string $message): void
    {
        $this->writeln('[SUCCESS] ' . $message);
    }

    public function info(string $message): void
    {
        $this->writeln('[INFO] ' . $message);
    }

    public function warning(string $message): void
    {
        $this->writeln('[WARNING] ' . $message);
    }

    public function getVerbosity(): Verbosity
    {
        return $this->verbosity;
    }

    public function setVerbosity(Verbosity $verbosity): void
    {
        $this->verbosity = $verbosity;
    }

    public function isQuiet(): bool
    {
        return $this->verbosity === Verbosity::Quiet;
    }

    public function isVerbose(): bool
    {
        return $this->verbosity->showsVerbose();
    }

    public function isDebug(): bool
    {
        return $this->verbosity->showsDebug();
    }

    public function newLine(int $count = 1): void
    {
        $this->write(str_repeat(PHP_EOL, $count));
    }

    /**
     * Get the buffered output content.
     */
    public function fetch(): string
    {
        $content = $this->buffer;
        $this->buffer = '';
        return $content;
    }

    /**
     * Get the buffered error content.
     */
    public function fetchError(): string
    {
        $content = $this->errorBuffer;
        $this->errorBuffer = '';
        return $content;
    }

    /**
     * Get the current buffer content without clearing.
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Get the current error buffer content without clearing.
     */
    public function getErrorBuffer(): string
    {
        return $this->errorBuffer;
    }

    /**
     * Clear all buffers.
     */
    public function clear(): void
    {
        $this->buffer = '';
        $this->errorBuffer = '';
    }
}
