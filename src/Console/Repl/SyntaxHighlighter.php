<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function preg_replace_callback;
use function sprintf;
use function strtolower;

/**
 * Colorizes PHP code for terminal display using ANSI escape sequences.
 *
 * Highlights keywords, strings, numbers, comments, variables, and types.
 * Designed for REPL output: not a full parser, but sufficient for
 * interactive single/multi-line snippets.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SyntaxHighlighter
{
    // ANSI color codes
    private const string RESET = "\033[0m";
    private const string KEYWORD = "\033[1;34m";   // Bold blue
    private const string STRING = "\033[0;32m";     // Green
    private const string NUMBER = "\033[0;36m";     // Cyan
    private const string COMMENT = "\033[0;90m";    // Gray
    private const string VARIABLE = "\033[0;33m";   // Yellow
    private const string TYPE = "\033[0;35m";       // Magenta
    private const string FUNCTION = "\033[0;96m";   // Bright cyan
    private const string CONSTANT = "\033[1;31m";   // Bold red

    /** @var list<string> */
    private const array KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'do',
        'echo', 'else', 'elseif', 'enum', 'extends', 'final', 'finally',
        'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'interface', 'list',
        'match', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'use', 'var', 'while', 'xor', 'yield',
        'yield from',
    ];

    /** @var list<string> */
    private const array TYPES = [
        'int', 'float', 'string', 'bool', 'void', 'null', 'mixed',
        'never', 'true', 'false', 'self', 'parent', 'object', 'iterable',
    ];

    /** @var list<string> */
    private const array CONSTANTS = [
        'null', 'true', 'false', 'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN',
        'PHP_FLOAT_MAX', 'PHP_FLOAT_MIN', 'PHP_MAJOR_VERSION', 'STDIN',
        'STDOUT', 'STDERR',
    ];

    private bool $enabled;

    public function __construct(
        bool $colorsEnabled = true,
    ) {
        $this->enabled = $colorsEnabled;
    }

    /**
     * Highlight a PHP code string with ANSI colors.
     *
     * Processes tokens in order: comments, strings, variables, numbers,
     * keywords, types, and constants. Each match is wrapped in ANSI
     * escape sequences for terminal display.
     */
    #[NoDiscard]
    public function highlight(string $code): string
    {
        if (!$this->enabled) {
            return $code;
        }

        // Order matters: strings and comments must be matched first
        // to prevent keyword highlighting inside them.

        // Single-line comments: // ... or # ...
        $code = preg_replace_callback(
            '#(//[^\n]*|(?<!\S)\#[^\n]*)#',
            fn(array $m): string => $this->wrap($m[0], self::COMMENT),
            $code,
        ) ?? $code;

        // Multi-line comments: /* ... */
        $code = preg_replace_callback(
            '#(/\*.*?\*/)#s',
            fn(array $m): string => $this->wrap($m[0], self::COMMENT),
            $code,
        ) ?? $code;

        // Double-quoted strings (with escape sequences)
        $code = preg_replace_callback(
            '#"(?:[^"\\\\]|\\\\.)*"#s',
            fn(array $m): string => $this->wrap($m[0], self::STRING),
            $code,
        ) ?? $code;

        // Single-quoted strings
        $code = preg_replace_callback(
            "#'(?:[^'\\\\]|\\\\.)*'#s",
            fn(array $m): string => $this->wrap($m[0], self::STRING),
            $code,
        ) ?? $code;

        // Variables: $name, $this, $$dynamic
        $code = preg_replace_callback(
            '#(\$\$?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)#',
            fn(array $m): string => $this->wrap($m[0], self::VARIABLE),
            $code,
        ) ?? $code;

        // Numbers: integers, floats, hex, octal, binary
        // Negative lookbehind prevents matching digits inside ANSI escape sequences
        $code = preg_replace_callback(
            '#(?<!\033\[)(?<!\033\[\d)(?<!;)\b(0[xX][0-9a-fA-F]+|0[bB][01]+|0[oO][0-7]+|\d+\.?\d*(?:[eE][+-]?\d+)?)\b(?!m)#',
            fn(array $m): string => $this->wrap($m[0], self::NUMBER),
            $code,
        ) ?? $code;

        // Function calls: name(
        $code = preg_replace_callback(
            '#\b([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)(\s*\()#',
            fn(array $m): string => $this->isKeyword($m[1])
                ? $this->wrap($m[1], self::KEYWORD) . $m[2]
                : $this->wrap($m[1], self::FUNCTION) . $m[2],
            $code,
        ) ?? $code;

        // Keywords (standalone words only, not already colored)
        $code = preg_replace_callback(
            '#(?<!\033)\b(' . $this->buildWordPattern(self::KEYWORDS) . ')\b(?!\s*\()#',
            fn(array $m): string => $this->wrap($m[0], self::KEYWORD),
            $code,
        ) ?? $code;

        // Type keywords
        $code = preg_replace_callback(
            '#(?<!\033)\b(' . $this->buildWordPattern(self::TYPES) . ')\b#',
            fn(array $m): string => $this->wrap($m[0], self::TYPE),
            $code,
        ) ?? $code;

        // Constants
        $code = preg_replace_callback(
            '#\b(' . $this->buildWordPattern(self::CONSTANTS) . ')\b#',
            fn(array $m): string => $this->wrap($m[0], self::CONSTANT),
            $code,
        ) ?? $code;

        return $code;
    }

    /**
     * Strip all ANSI escape sequences from a string.
     */
    #[NoDiscard]
    public static function stripAnsi(string $text): string
    {
        return preg_replace('#\033\[[0-9;]*m#', '', $text) ?? $text;
    }

    /**
     * Wrap text in ANSI color codes.
     */
    private function wrap(string $text, string $color): string
    {
        return sprintf('%s%s%s', $color, $text, self::RESET);
    }

    /**
     * Build a regex alternation pattern from a word list.
     *
     * @param list<string> $words
     */
    private function buildWordPattern(array $words): string
    {
        return implode('|', $words);
    }

    /**
     * Check if a word is a PHP keyword.
     */
    private function isKeyword(string $word): bool
    {
        return in_array(strtolower($word), self::KEYWORDS, true);
    }
}
