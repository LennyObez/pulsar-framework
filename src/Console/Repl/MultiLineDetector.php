<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;

use function strlen;
use function substr;

/**
 * Detects whether a PHP expression is complete or requires continuation.
 *
 * Tracks unclosed braces, parentheses, brackets, and string literals
 * to determine if the user needs to continue input on the next line.
 * @api
 */
#[Api(since: '1.0.0')]
final class MultiLineDetector
{
    /**
     * Check whether the input is complete (all delimiters balanced).
     *
     * Returns false when the input has:
     * - Unclosed braces `{}`
     * - Unclosed parentheses `()`
     * - Unclosed brackets `[]`
     * - Unclosed string literals (single or double quoted)
     * - Unclosed heredoc/nowdoc
     * - Trailing backslash (line continuation)
     */
    #[NoDiscard]
    public function isComplete(string $input): bool
    {
        $state = $this->analyze($input);

        return $state->isComplete();
    }

    /**
     * Analyze the input and return its delimiter state.
     */
    #[NoDiscard]
    public function analyze(string $input): MultiLineState
    {
        $braces = 0;
        $parens = 0;
        $brackets = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inHeredoc = false;
        $heredocTag = '';
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];
            $next = ($i + 1 < $len) ? $input[$i + 1] : '';

            // Handle string states first
            if ($inSingleQuote) {
                if ($char === '\\' && $next === "'") {
                    $i++; // Skip escaped quote

                    continue;
                }

                if ($char === "'") {
                    $inSingleQuote = false;
                }

                continue;
            }

            if ($inDoubleQuote) {
                if ($char === '\\') {
                    $i++; // Skip escaped character

                    continue;
                }

                if ($char === '"') {
                    $inDoubleQuote = false;
                }

                continue;
            }

            if ($inHeredoc) {
                // Check if this line is the closing tag
                $lineStart = ($i === 0 || $input[$i - 1] === "\n");

                if ($lineStart) {
                    $remaining = substr($input, $i);
                    $closingPattern = $heredocTag;

                    if (str_starts_with($remaining, $closingPattern)) {
                        $afterTag = $i + strlen($closingPattern);

                        if ($afterTag >= $len
                            || $input[$afterTag] === ';'
                            || $input[$afterTag] === "\n") {
                            $inHeredoc = false;
                            $heredocTag = '';
                            $i += strlen($closingPattern) - 1;
                        }
                    }
                }

                continue;
            }

            // Skip single-line comments
            if ($char === '/' && $next === '/') {
                while ($i < $len && $input[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '#' && $next !== '[') {
                while ($i < $len && $input[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            // Skip multi-line comments
            if ($char === '/' && $next === '*') {
                $i += 2;

                while ($i < $len - 1) {
                    if ($input[$i] === '*' && $input[$i + 1] === '/') {
                        $i++;

                        break;
                    }

                    $i++;
                }

                continue;
            }

            // Heredoc/nowdoc detection: <<<TAG or <<<'TAG'
            if ($char === '<' && $next === '<'
                && ($i + 2 < $len) && $input[$i + 2] === '<') {
                $rest = substr($input, $i + 3);

                if (preg_match('/^\s*\'?([a-zA-Z_]\w*)\'?\s*$/m', explode("\n", $rest, 2)[0], $matches)) {
                    $inHeredoc = true;
                    $heredocTag = $matches[1];
                    // Advance past the opening line
                    $nlPos = strpos($input, "\n", $i);

                    if ($nlPos !== false) {
                        $i = $nlPos;
                    } else {
                        $i = $len - 1;
                    }

                    continue;
                }
            }

            // String opening
            if ($char === "'") {
                $inSingleQuote = true;

                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;

                continue;
            }

            // Delimiter tracking
            match ($char) {
                '{' => $braces++,
                '}' => $braces--,
                '(' => $parens++,
                ')' => $parens--,
                '[' => $brackets++,
                ']' => $brackets--,
                default => null,
            };
        }

        // Check for trailing backslash (line continuation)
        $trimmed = rtrim($input);
        $trailingBackslash = $trimmed !== '' && $trimmed[strlen($trimmed) - 1] === '\\';

        return new MultiLineState(
            braces: $braces,
            parentheses: $parens,
            brackets: $brackets,
            inSingleQuote: $inSingleQuote,
            inDoubleQuote: $inDoubleQuote,
            inHeredoc: $inHeredoc,
            trailingBackslash: $trailingBackslash,
        );
    }
}
