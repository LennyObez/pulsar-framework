<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

use function array_slice;
use function basename;
use function count;
use function file_exists;
use function file_get_contents;
use function implode;
use function max;
use function min;
use function sprintf;
use function str_pad;
use function strlen;

/**
 * Renders exceptions with highlighted stack traces and source context.
 *
 * Shows the failing line with surrounding code, colorized exception
 * chain, and condensed stack frames for readable error output.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ErrorRenderer
{
    private const int CONTEXT_LINES = 3;

    private const string YELLOW = "\033[0;33m";
    private const string GRAY = "\033[0;90m";
    private const string CYAN = "\033[0;36m";
    private const string BOLD_RED = "\033[1;31m";
    private const string BOLD_WHITE = "\033[1;37m";
    private const string RED_BG = "\033[41;37m";
    private const string RESET = "\033[0m";

    public function __construct(
        private bool $colorsEnabled = true,
        private int $contextLines = self::CONTEXT_LINES,
    ) {}

    /**
     * Render a throwable as a formatted error string.
     *
     * Includes the exception class, message, source context (if file exists),
     * stack trace, and any chained previous exceptions.
     */
    #[NoDiscard]
    public function render(Throwable $exception): string
    {
        $lines = [];

        // Exception chain: walk from current to root cause
        $depth = 0;
        $current = $exception;

        while ($current !== null) {
            if ($depth > 0) {
                $lines[] = '';
                $lines[] = $this->color(sprintf('Caused by (%d):', $depth), self::YELLOW);
            }

            $lines[] = $this->renderSingleException($current);
            $current = $current->getPrevious();
            $depth++;

            // Safety limit for circular exception chains
            if ($depth > 10) {
                $lines[] = $this->color('... (exception chain truncated)', self::GRAY);

                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render source context around a file:line location.
     *
     * Returns empty string if the file doesn't exist or is unreadable.
     */
    #[NoDiscard]
    public function renderSourceContext(string $file, int $line): string
    {
        if (!file_exists($file)) {
            return '';
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return '';
        }

        $allLines = explode("\n", $contents);
        $totalLines = count($allLines);

        $start = max(0, $line - $this->contextLines - 1);
        $end = min($totalLines, $line + $this->contextLines);
        $contextSlice = array_slice($allLines, $start, $end - $start);

        $lineNumWidth = strlen((string) $end);
        $output = [];
        $output[] = $this->color(
            sprintf('  at %s:%d', basename($file), $line),
            self::CYAN,
        );

        foreach ($contextSlice as $i => $codeLine) {
            $currentLineNum = $start + $i + 1;
            $lineNumStr = str_pad((string) $currentLineNum, $lineNumWidth, ' ', STR_PAD_LEFT);

            if ($currentLineNum === $line) {
                // Highlight the error line
                $output[] = $this->color(
                    sprintf('  %s > %s', $lineNumStr, $codeLine),
                    self::RED_BG,
                );
            } else {
                $output[] = $this->color(
                    sprintf('  %s | ', $lineNumStr),
                    self::GRAY,
                ) . $codeLine;
            }
        }

        return implode("\n", $output);
    }

    /**
     * Render a single exception (without chaining).
     */
    private function renderSingleException(Throwable $exception): string
    {
        $lines = [];

        // Header: exception class and message
        $lines[] = '';
        $lines[] = $this->color(
            sprintf('  %s ', $exception::class),
            self::BOLD_RED,
        );
        $lines[] = '';
        $lines[] = $this->color(
            '  ' . $exception->getMessage(),
            self::BOLD_WHITE,
        );

        // Source context
        $sourceContext = $this->renderSourceContext(
            $exception->getFile(),
            $exception->getLine(),
        );

        if ($sourceContext !== '') {
            $lines[] = '';
            $lines[] = $sourceContext;
        }

        // Stack trace
        $lines[] = '';
        $lines[] = $this->renderStackTrace($exception);

        return implode("\n", $lines);
    }

    /**
     * Render a condensed stack trace.
     */
    private function renderStackTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();
        $lines = [$this->color('  Stack trace:', self::GRAY)];
        $maxFrames = 15;

        foreach (array_slice($trace, 0, $maxFrames) as $i => $frame) {
            $file = $frame['file'] ?? '<internal>';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '<unknown>';

            $caller = $class !== '' ? $class . $type . $function : $function;

            $lines[] = sprintf(
                '  %s %s %s',
                $this->color(sprintf('#%d', $i), self::GRAY),
                $this->color($caller . '()', self::YELLOW),
                $this->color(
                    sprintf('%s:%d', basename($file), $line),
                    self::CYAN,
                ),
            );
        }

        $remaining = count($trace) - $maxFrames;

        if ($remaining > 0) {
            $lines[] = $this->color(
                sprintf('  ... %d more frames', $remaining),
                self::GRAY,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Apply ANSI color if colors are enabled.
     */
    private function color(string $text, string $code): string
    {
        if (!$this->colorsEnabled) {
            return $text;
        }

        return $code . $text . self::RESET;
    }
}
