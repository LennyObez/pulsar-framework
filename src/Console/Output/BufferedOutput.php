<?php

declare(strict_types=1);

namespace Pulsar\Console\Output;

use Override;
use Pulsar\Api\Api;
use Pulsar\Console\OutputInterface;
use Pulsar\Console\Verbosity;

/**
 * Output that buffers content (useful for testing).
 * @api
 */
#[Api(since: '1.0.0')]
final class BufferedOutput implements OutputInterface
{
    public private(set) string $buffer = '';
    public private(set) string $errorBuffer = '';
    public Verbosity $verbosity;

    public function __construct(Verbosity $verbosity = Verbosity::Normal)
    {
        $this->verbosity = $verbosity;
    }

    #[Override]
    public function write(string $message): void
    {
        if ($this->verbosity === Verbosity::Quiet) {
            return;
        }

        $this->buffer .= $message;
    }

    #[Override]
    public function writeln(string $message = ''): void
    {
        $this->write($message . PHP_EOL);
    }

    #[Override]
    public function error(string $message): void
    {
        if ($this->verbosity === Verbosity::Quiet) {
            return;
        }

        $this->errorBuffer .= $message;
    }

    #[Override]
    public function errorln(string $message = ''): void
    {
        $this->error($message . PHP_EOL);
    }

    #[Override]
    public function success(string $message): void
    {
        $this->writeln('[SUCCESS] ' . $message);
    }

    #[Override]
    public function info(string $message): void
    {
        $this->writeln('[INFO] ' . $message);
    }

    #[Override]
    public function warning(string $message): void
    {
        $this->writeln('[WARNING] ' . $message);
    }

    #[Override]
    public function isQuiet(): bool
    {
        return $this->verbosity === Verbosity::Quiet;
    }

    #[Override]
    public function isVerbose(): bool
    {
        return $this->verbosity->showsVerbose();
    }

    #[Override]
    public function isDebug(): bool
    {
        return $this->verbosity->showsDebug();
    }

    #[Override]
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
     * Clear all buffers.
     */
    public function clear(): void
    {
        $this->buffer = '';
        $this->errorBuffer = '';
    }
}
