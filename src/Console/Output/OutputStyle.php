<?php

declare(strict_types=1);

namespace Pulsar\Console\Output;

use Pulsar\Api\Api;
use Pulsar\Console\OutputInterface;

use function max;
use function mb_strlen;
use function sprintf;
use function str_pad;
use function str_repeat;

/**
 * Rich ANSI-styled console output.
 *
 * Provides semantic styling (info, success, warning, error), tables,
 * progress bars, and other formatting utilities. Automatically degrades
 * to plain text when the terminal does not support ANSI escape codes.
 */
#[Api(since: '1.0.0')]
final class OutputStyle
{
    private const string RESET = "\033[0m";
    private const string BOLD = "\033[1m";
    private const string DIM = "\033[2m";
    private const string UNDERLINE = "\033[4m";

    // Foreground colors
    private const string FG_RED = "\033[31m";
    private const string FG_GREEN = "\033[32m";
    private const string FG_YELLOW = "\033[33m";
    private const string FG_BLUE = "\033[34m";
    private const string FG_CYAN = "\033[36m";
    private const string FG_WHITE = "\033[37m";

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $decorated = true,
    ) {}

    /**
     * Write an info-styled message (blue text).
     */
    public function info(string $message): void
    {
        $this->styledLine(self::FG_BLUE, 'INFO', $message);
    }

    /**
     * Write a success-styled message (green text).
     */
    public function success(string $message): void
    {
        $this->styledLine(self::FG_GREEN, 'OK', $message);
    }

    /**
     * Write a warning-styled message (yellow text).
     */
    public function warning(string $message): void
    {
        $this->styledLine(self::FG_YELLOW, 'WARN', $message);
    }

    /**
     * Write an error-styled message (red text).
     */
    public function error(string $message): void
    {
        $this->styledLine(self::FG_RED, 'ERROR', $message);
    }

    /**
     * Write a comment-styled message (dim/gray text).
     */
    public function comment(string $message): void
    {
        if ($this->decorated) {
            $this->output->writeln(self::DIM . $message . self::RESET);
        } else {
            $this->output->writeln('// ' . $message);
        }
    }

    /**
     * Write a title (bold, underlined).
     */
    public function title(string $title): void
    {
        $this->output->newLine();

        if ($this->decorated) {
            $this->output->writeln(self::BOLD . self::UNDERLINE . $title . self::RESET);
        } else {
            $this->output->writeln($title);
            $this->output->writeln(str_repeat('=', mb_strlen($title)));
        }

        $this->output->newLine();
    }

    /**
     * Write a section heading (bold).
     */
    public function section(string $heading): void
    {
        $this->output->newLine();

        if ($this->decorated) {
            $this->output->writeln(self::BOLD . $heading . self::RESET);
        } else {
            $this->output->writeln($heading);
            $this->output->writeln(str_repeat('-', mb_strlen($heading)));
        }

        $this->output->newLine();
    }

    /**
     * Write a bulleted list.
     *
     * @param list<string> $items
     */
    public function listing(array $items): void
    {
        foreach ($items as $item) {
            $bullet = $this->decorated ? self::FG_GREEN . ' * ' . self::RESET : ' * ';
            $this->output->writeln($bullet . $item);
        }

        $this->output->newLine();
    }

    /**
     * Render a table to the output.
     *
     * @param list<string> $headers Column headers
     * @param list<list<string>> $rows Data rows
     */
    public function table(array $headers, array $rows): void
    {
        $formatter = new TableFormatter();
        $formatter->setHeaders($headers);
        $formatter->setRows($rows);
        $formatter->render($this->output);
    }

    /**
     * Create and display a progress bar.
     *
     * Returns a callable that advances the bar. Call it with no arguments
     * to advance by 1, or pass an amount.
     *
     * @param int $total Total steps
     * @param int $width Bar width in characters
     *
     * @return callable(int=): void Advance function
     */
    public function progressBar(int $total, int $width = 40): callable
    {
        $current = 0;

        $render = function () use (&$current, $total, $width): void {
            /** @var int $current */
            $percent = $total > 0 ? (int) (($current / $total) * 100) : 0;
            $filled = $total > 0 ? (int) (($current / $total) * $width) : 0;
            $empty = $width - $filled;

            $bar = str_repeat('=', max(0, $filled));
            $remaining = str_repeat(' ', max(0, $empty));

            if ($this->decorated) {
                $this->output->write(sprintf(
                    "\r %s[%s%s>%s]%s %3d%% (%d/%d)",
                    self::FG_GREEN,
                    $bar,
                    self::FG_WHITE,
                    $remaining,
                    self::RESET,
                    $percent,
                    $current,
                    $total,
                ));
            } else {
                $this->output->write(sprintf(
                    "\r [%s>%s] %3d%% (%d/%d)",
                    $bar,
                    $remaining,
                    $percent,
                    $current,
                    $total,
                ));
            }

            if ($current >= $total) {
                $this->output->newLine();
            }
        };

        $render();

        return static function (int $step = 1) use (&$current, $render): void {
            /** @var int $current */
            $current += $step;
            $render();
        };
    }

    /**
     * Write a key-value definition list.
     *
     * @param array<string, string> $pairs
     */
    public function definitionList(array $pairs): void
    {
        $maxKeyLength = 0;
        foreach (array_keys($pairs) as $key) {
            $maxKeyLength = max($maxKeyLength, mb_strlen($key));
        }

        foreach ($pairs as $key => $value) {
            $paddedKey = str_pad($key, $maxKeyLength);

            if ($this->decorated) {
                $this->output->writeln(sprintf(' %s%s%s : %s', self::FG_CYAN, $paddedKey, self::RESET, $value));
            } else {
                $this->output->writeln(sprintf(' %s : %s', $paddedKey, $value));
            }
        }
    }

    /**
     * Write a horizontal rule.
     */
    public function horizontalRule(int $width = 60): void
    {
        $line = str_repeat('─', $width);

        if ($this->decorated) {
            $this->output->writeln(self::DIM . $line . self::RESET);
        } else {
            $this->output->writeln($line);
        }
    }

    /**
     * Write a formatted line with a prefix label.
     */
    private function styledLine(string $color, string $label, string $message): void
    {
        if ($this->decorated) {
            $this->output->writeln(sprintf(
                ' %s%s %s %s %s',
                $color,
                self::BOLD,
                $label,
                self::RESET,
                $message,
            ));
        } else {
            $this->output->writeln(sprintf(' [%s] %s', $label, $message));
        }
    }
}
