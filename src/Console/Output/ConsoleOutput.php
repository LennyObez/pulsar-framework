<?php

declare(strict_types=1);

namespace Pulsar\Console\Output;

use Override;
use Pulsar\Console\OutputInterface;
use Pulsar\Console\Verbosity;

use function function_exists;

/**
 * Output to the console (STDOUT/STDERR).
 */
final class ConsoleOutput implements OutputInterface
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    public Verbosity $verbosity;

    public bool $decorated;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        mixed $stdout = null,
        mixed $stderr = null,
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
     *
     * F3.7: the previous Windows Terminal probe used
     * `str_starts_with((string) getenv('WT_SESSION'), '')`, which is always
     * true (every string starts with the empty string). The branch made
     * the entire Windows colour-detection always succeed regardless of
     * whether Windows Terminal was actually present. The fix is to test
     * `WT_SESSION !== false && WT_SESSION !== ''` — Windows Terminal
     * sets that env to a GUID, so a non-empty value is the right signal.
     */
    private function hasColorSupport(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $wtSession = getenv('WT_SESSION');

            return str_contains(PHP_OS, 'WIN')
                && (getenv('ANSICON') !== false
                    || getenv('ConEmuANSI') === 'ON'
                    || getenv('TERM') === 'xterm'
                    || ($wtSession !== false && $wtSession !== ''));
        }

        return function_exists('posix_isatty') && @posix_isatty($this->stdout);
    }

    #[Override]
    public function write(string $message): void
    {
        if ($this->verbosity === Verbosity::Quiet) {
            return;
        }

        fwrite($this->stdout, $message);
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

        $formatted = $this->decorated
            ? "\033[31m" . $message . "\033[0m"
            : '[ERROR] ' . $message;

        fwrite($this->stderr, $formatted);
    }

    #[Override]
    public function errorln(string $message = ''): void
    {
        $this->error($message . PHP_EOL);
    }

    #[Override]
    public function success(string $message): void
    {
        $formatted = $this->decorated
            ? "\033[32m" . $message . "\033[0m"
            : '[OK] ' . $message;

        $this->writeln($formatted);
    }

    #[Override]
    public function info(string $message): void
    {
        $formatted = $this->decorated
            ? "\033[34m" . $message . "\033[0m"
            : '[INFO] ' . $message;

        $this->writeln($formatted);
    }

    #[Override]
    public function warning(string $message): void
    {
        $formatted = $this->decorated
            ? "\033[33m" . $message . "\033[0m"
            : '[WARN] ' . $message;

        $this->writeln($formatted);
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

}
