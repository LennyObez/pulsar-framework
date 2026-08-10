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
     * Windows Terminal is detected by `WT_SESSION` being both set and
     * non-empty: it publishes a GUID there, so a non-empty value is the
     * signal. Test it that way and not with `str_starts_with(..., '')`,
     * which is true of every string and would make the whole Windows
     * branch report colour support unconditionally.
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
