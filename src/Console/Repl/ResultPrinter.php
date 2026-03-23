<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use BackedEnum;
use Countable;
use NoDiscard;
use Pulsar\Api\Api;
use ReflectionClass;
use Stringable;
use Traversable;
use UnitEnum;

use function array_slice;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_null;
use function is_object;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_pad;
use function str_repeat;

/**
 * Formats return values for REPL display.
 *
 * Renders objects as property tables, arrays as indented trees,
 * scalars with their types, and collections with count + preview.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ResultPrinter
{
    private const string INDENT = '  ';
    private const int MAX_STRING_LENGTH = 200;
    private const int MAX_ARRAY_ITEMS = 20;
    private const int MAX_DEPTH = 5;

    public function __construct(
        private ?SecretRedactor $redactor = null,
    ) {}

    /**
     * Format a value for REPL display.
     */
    #[NoDiscard]
    public function format(mixed $value, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '{ ... (max depth) }';
        }

        if (is_null($value)) {
            return $this->colorize('null', 'cyan');
        }

        if (is_bool($value)) {
            return $this->colorize($value ? 'true' : 'false', 'yellow');
        }

        if (is_int($value)) {
            return $this->colorize((string) $value, 'cyan');
        }

        if (is_float($value)) {
            return $this->colorize(sprintf('%g', $value), 'cyan');
        }

        if (is_string($value)) {
            return $this->formatString($value);
        }

        if (is_array($value)) {
            return $this->formatArray($value, $depth);
        }

        if (is_object($value)) {
            return $this->formatObject($value, $depth);
        }

        return $this->colorize(get_debug_type($value), 'gray');
    }

    /**
     * Format a value as a single-line summary (for collection items, etc.).
     */
    #[NoDiscard]
    public function summary(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $truncated = mb_strlen($value) > 50 ? mb_substr($value, 0, 50) . '...' : $value;

            return sprintf('"%s"', $truncated);
        }

        if (is_array($value)) {
            return sprintf('array(%d)', count($value));
        }

        if (is_object($value)) {
            return sprintf('%s {...}', $value::class);
        }

        return get_debug_type($value);
    }

    /**
     * Format a table from an array of rows.
     *
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     */
    #[NoDiscard]
    public function formatTable(array $rows, array $headers): string
    {
        if ($rows === []) {
            return '(empty result set)';
        }

        // Calculate column widths
        $widths = [];

        foreach ($headers as $header) {
            $widths[$header] = mb_strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $len = mb_strlen($value);

                if ($len > ($widths[$header] ?? 0)) {
                    $widths[$header] = $len;
                }
            }
        }

        // Build separator
        $separatorParts = [];

        foreach ($headers as $header) {
            $separatorParts[] = str_repeat('-', ($widths[$header] ?? 0) + 2);
        }

        $separator = '+' . implode('+', $separatorParts) . '+';

        // Build header row
        $headerParts = [];

        foreach ($headers as $header) {
            $headerParts[] = ' ' . str_pad($header, $widths[$header] ?? 0) . ' ';
        }

        $headerLine = '|' . implode('|', $headerParts) . '|';

        // Build data rows
        $lines = [$separator, $headerLine, $separator];

        foreach ($rows as $row) {
            $parts = [];

            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $parts[] = ' ' . str_pad($value, $widths[$header] ?? 0) . ' ';
            }

            $lines[] = '|' . implode('|', $parts) . '|';
        }

        $lines[] = $separator;

        return implode("\n", $lines);
    }

    /**
     * Format a string value with type annotation and truncation.
     */
    private function formatString(string $value): string
    {
        $redacted = $this->redactor?->redactOutput($value) ?? $value;
        $len = mb_strlen($redacted);

        if ($len > self::MAX_STRING_LENGTH) {
            $truncated = mb_substr($redacted, 0, self::MAX_STRING_LENGTH);

            return sprintf(
                '%s "%s..." %s',
                $this->colorize('string(' . $len . ')', 'gray'),
                $this->colorize($truncated, 'green'),
                $this->colorize('(truncated)', 'gray'),
            );
        }

        return sprintf(
            '%s "%s"',
            $this->colorize('string(' . $len . ')', 'gray'),
            $this->colorize($redacted, 'green'),
        );
    }

    /**
     * Format an array value as an indented tree.
     *
     * @param array<mixed> $value
     */
    private function formatArray(array $value, int $depth): string
    {
        $count = count($value);

        if ($count === 0) {
            return '[]';
        }

        $indent = str_repeat(self::INDENT, $depth + 1);
        $closingIndent = str_repeat(self::INDENT, $depth);
        $lines = [sprintf('array(%d) [', $count)];
        $shown = 0;

        foreach ($value as $key => $item) {
            if ($shown >= self::MAX_ARRAY_ITEMS) {
                $remaining = $count - $shown;
                $lines[] = $indent . $this->colorize("... {$remaining} more items", 'gray');

                break;
            }

            $keyStr = is_string($key)
                ? sprintf('"%s"', $key)
                : (string) $key;

            $lines[] = $indent . $keyStr . ' => ' . $this->format($item, $depth + 1) . ',';
            $shown++;
        }

        $lines[] = $closingIndent . ']';

        return implode("\n", $lines);
    }

    /**
     * Format an object for display.
     */
    private function formatObject(object $value, int $depth): string
    {
        // Enums
        if ($value instanceof UnitEnum) {
            return $this->formatEnum($value);
        }

        // Countable collections
        if ($value instanceof Countable) {
            return $this->formatCountable($value, $depth);
        }

        // Stringable objects
        if ($value instanceof Stringable) {
            return sprintf(
                '%s { %s }',
                $this->colorize($value::class, 'magenta'),
                $this->colorize((string) $value, 'green'),
            );
        }

        // General objects: property table
        return $this->formatObjectProperties($value, $depth);
    }

    /**
     * Format an enum value.
     */
    private function formatEnum(UnitEnum $value): string
    {
        if ($value instanceof BackedEnum) {
            return sprintf(
                '%s::%s (%s)',
                $this->colorize($value::class, 'magenta'),
                $this->colorize($value->name, 'yellow'),
                $this->format($value->value),
            );
        }

        return sprintf(
            '%s::%s',
            $this->colorize($value::class, 'magenta'),
            $this->colorize($value->name, 'yellow'),
        );
    }

    /**
     * Format a Countable object with count + first/last items.
     */
    private function formatCountable(Countable $value, int $depth): string
    {
        $count = count($value);
        $header = sprintf(
            '%s (count: %s)',
            $this->colorize($value::class, 'magenta'),
            $this->colorize((string) $count, 'cyan'),
        );

        if ($count === 0) {
            return $header . ' []';
        }

        // If it's also iterable, show first/last items
        if ($value instanceof Traversable) {
            $items = [];

            foreach ($value as $item) {
                $items[] = $item;

                if (count($items) > self::MAX_ARRAY_ITEMS) {
                    break;
                }
            }

            $indent = str_repeat(self::INDENT, $depth + 1);
            $closingIndent = str_repeat(self::INDENT, $depth);
            $lines = [$header . ' ['];

            foreach (array_slice($items, 0, 5) as $i => $item) {
                $lines[] = $indent . $i . ' => ' . $this->format($item, $depth + 1) . ',';
            }

            if ($count > 5) {
                $lines[] = $indent . $this->colorize('... ' . ($count - 5) . ' more items', 'gray');
            }

            $lines[] = $closingIndent . ']';

            return implode("\n", $lines);
        }

        return $header;
    }

    /**
     * Format an object's public properties as a property table.
     */
    private function formatObjectProperties(object $value, int $depth): string
    {
        $className = $value::class;
        $reflection = new ReflectionClass($value);
        $properties = $reflection->getProperties();

        if ($properties === []) {
            return $this->colorize($className, 'magenta') . ' {}';
        }

        $indent = str_repeat(self::INDENT, $depth + 1);
        $closingIndent = str_repeat(self::INDENT, $depth);
        $lines = [$this->colorize($className, 'magenta') . ' {'];

        // Determine max property name length for alignment
        $maxNameLen = 0;

        foreach ($properties as $prop) {
            if ($prop->isPublic()) {
                $len = mb_strlen($prop->getName());

                if ($len > $maxNameLen) {
                    $maxNameLen = $len;
                }
            }
        }

        foreach ($properties as $prop) {
            if (!$prop->isPublic()) {
                continue;
            }

            $name = $prop->getName();
            $paddedName = str_pad($name, $maxNameLen);

            if (!$prop->isInitialized($value)) {
                $lines[] = $indent . $this->colorize($paddedName, 'yellow') . ': '
                    . $this->colorize('<uninitialized>', 'gray');

                continue;
            }

            $propValue = $prop->getValue($value);

            // Apply secret redaction if available
            if ($this->redactor !== null) {
                $dump = $this->redactor->redactObjectDump($value);

                if (isset($dump[$name]) && $dump[$name] === '********') {
                    $lines[] = $indent . $this->colorize($paddedName, 'yellow') . ': '
                        . $this->colorize('********', 'red');

                    continue;
                }
            }

            $lines[] = $indent . $this->colorize($paddedName, 'yellow') . ': '
                . $this->format($propValue, $depth + 1);
        }

        $lines[] = $closingIndent . '}';

        return implode("\n", $lines);
    }

    /**
     * Apply a named color to text using ANSI codes.
     */
    private function colorize(string $text, string $color): string
    {
        $codes = [
            'red' => "\033[0;31m",
            'green' => "\033[0;32m",
            'yellow' => "\033[0;33m",
            'blue' => "\033[0;34m",
            'magenta' => "\033[0;35m",
            'cyan' => "\033[0;36m",
            'gray' => "\033[0;90m",
        ];

        $code = $codes[$color] ?? '';

        if ($code === '') {
            return $text;
        }

        return $code . $text . "\033[0m";
    }
}
