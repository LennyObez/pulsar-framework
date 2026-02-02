<?php

declare(strict_types=1);

namespace Pulsar\Console\Output;

use function function_exists;

use Pulsar\Console\OutputInterface;
use Pulsar\Console\Verbosity;

/**
 * Output to the console (STDOUT/STDERR).
 */
final class ConsoleOutput implements OutputInterface
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private Verbosity $verbosity;

    private bool $decorated;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        $stdout = null,
        $stderr = null,
        Verbosity $verbosity = Verbosity::Normal,
        ?bool $decorated = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->verbosity = $verbosity;
        $this->decorated = $decorated ?? $this->hasColorSupport();
    }

    /**
     * Check if the terminal supports colors.
     */
    private function hasColorSupport(): bool
    {
        // Windows 10+ supports ANSI codes
        if (DIRECTORY_SEPARATOR === '\\') {
            return str_contains(PHP_OS, 'WIN')
                && (getenv('ANSICON') !== false
                    || getenv('ConEmuANSI') === 'ON'
                    || getenv('TERM') === 'xterm'
                    || str_starts_with((string) getenv('WT_SESSION'), ''));
        }

        return function_exists('posix_isatty') && @posix_isatty($this->stdout);
    }

    public function write(string $message): void
    {
        if ($this->verbosity === Verbosity::Quiet) {
            return;
        }

        fwrite($this->stdout, $message);
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

        $formatted = $this->decorated
            ? "\033[31m" . $message . "\033[0m"
            : $message;

        fwrite($this->stderr, $formatted);
    }

    public function errorln(string $message = ''): void
    {
        $this->error($message . PHP_EOL);
    }

    public function success(string $message): void
    {
        $formatted = $this->decorated
            ? "\033[32m" . $message . "\033[0m"
            : $message;

        $this->writeln($formatted);
    }

    public function info(string $message): void
    {
        $formatted = $this->decorated
            ? "\033[34m" . $message . "\033[0m"
            : $message;

        $this->writeln($formatted);
    }

    public function warning(string $message): void
    {
        $formatted = $this->decorated
            ? "\033[33m" . $message . "\033[0m"
            : $message;

        $this->writeln($formatted);
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
     * Check if output is decorated (colored).
     */
    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    /**
     * Set whether output should be decorated.
     */
    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }
}
