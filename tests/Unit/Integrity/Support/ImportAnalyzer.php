<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function is_array;
use function ltrim;
use function preg_match;
use function str_starts_with;
use function substr;
use function token_get_all;
use function trim;

/**
 * Extracts all Pulsar class references from a PHP file using token_get_all().
 *
 * Handles:
 * - Simple use statements: use Foo\Bar;
 * - Grouped use statements: use Foo\{Bar, Baz};
 * - Aliased use statements: use Foo\Bar as Alias;
 * - Fully-qualified inline references: new \Pulsar\Foo\Bar()
 * - Skips: use function, use const, trait use inside classes
 */
final class ImportAnalyzer
{
    /**
     * Extract all Pulsar class references from a PHP file.
     *
     * Returns deduplicated FQCNs of all Pulsar classes referenced via
     * use statements or fully-qualified inline names.
     *
     * @return list<string>
     */
    public static function extractReferences(string $filePath): array
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            return [];
        }

        $tokens = token_get_all($code);
        $count = count($tokens);
        $references = [];
        $nestingLevel = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Track brace nesting to distinguish namespace-level use from trait use
            if ($token === '{') {
                $nestingLevel++;

                continue;
            }

            if ($token === '}') {
                $nestingLevel--;

                continue;
            }

            // Fully-qualified name token (PHP 8.0+): \Pulsar\Foo\Bar
            if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED) {
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'Pulsar\\')) {
                    $references[] = $name;
                }

                continue;
            }

            // Use statements (only at namespace level, nesting <= 1)
            if (is_array($token) && $token[0] === T_USE && $nestingLevel <= 1) {
                $parsed = self::parseUseStatement($tokens, $i, $count);
                foreach ($parsed as $fqcn) {
                    if (str_starts_with($fqcn, 'Pulsar\\')) {
                        $references[] = $fqcn;
                    }
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * Extract Pulsar FQCNs that appear as quoted strings in the file.
     *
     * F23.4: the static-import scan in {@see extractReferences()} sees
     * `T_USE` and `T_NAME_FULLY_QUALIFIED` tokens, but PHP code that
     * routes through `$container->get('Pulsar\Foo\Bar')` or
     * `class_exists('Pulsar\Foo\Bar')` keeps the FQCN inside a
     * `T_CONSTANT_ENCAPSED_STRING` — invisible to import analysis,
     * which is precisely the arbitrary-class-instantiation pattern
     * this audit infrastructure exists to surface (carry-over
     * F21.2 / F17.1 / F22.3 / F22.14).
     *
     * Scans `T_CONSTANT_ENCAPSED_STRING` for substrings matching
     * `Pulsar\<UpperCaseSegment>...` and returns the deduplicated
     * FQCNs. Handles single- and double-quoted PHP literals, both
     * with leading backslash (`\\Pulsar\\...`) and without.
     *
     * @return list<string>
     */
    public static function extractClassStringReferences(string $filePath): array
    {
        $code = file_get_contents($filePath);

        if ($code === false) {
            return [];
        }

        $tokens = token_get_all($code);
        $references = [];

        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = $token[1];

            if ($literal === '') {
                continue;
            }

            // Strip the surrounding quote pair. Single-quoted strings
            // do not need escape decoding; double-quoted ones with a
            // backslash-escaped namespace separator look like
            // "\\Pulsar\\Foo" in source, which token_get_all already
            // decodes to a single backslash.
            $first = $literal[0];

            if ($first !== "'" && $first !== '"') {
                continue;
            }

            $inner = trim(substr($literal, 1, -1));
            $candidate = ltrim($inner, '\\');

            if (
                preg_match('/^Pulsar(\\\\[A-Z][A-Za-z0-9_]*)+$/', $candidate) === 1
            ) {
                $references[] = $candidate;
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * Parse a use statement starting at the T_USE token position.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string> Parsed FQCNs
     */
    private static function parseUseStatement(array $tokens, int &$i, int $count): array
    {
        $i++; // Skip past T_USE

        // Skip whitespace
        while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }

        // Skip "use function" and "use const"
        if ($i < $count && is_array($tokens[$i])) {
            if ($tokens[$i][0] === T_FUNCTION || $tokens[$i][0] === T_CONST) {
                // Advance to semicolon
                while ($i < $count && $tokens[$i] !== ';') {
                    $i++;
                }

                return [];
            }
        }

        // Collect the namespace prefix and names
        $prefix = '';
        $names = [];
        $current = '';
        $inGroup = false;

        while ($i < $count && $tokens[$i] !== ';') {
            $token = $tokens[$i];

            if ($token === '{') {
                $prefix = $current;
                $current = '';
                $inGroup = true;
                $i++;

                continue;
            }

            if ($token === '}') {
                if ($current !== '') {
                    $names[] = $prefix . $current;
                    $current = '';
                }
                $inGroup = false;
                $i++;

                continue;
            }

            if ($token === ',') {
                if ($current !== '') {
                    $names[] = $inGroup ? $prefix . $current : $current;
                    $current = '';
                }
                $i++;

                continue;
            }

            // "as" keyword — skip alias name
            if (is_array($token) && $token[0] === T_AS) {
                // The current name is complete, add it
                if ($current !== '') {
                    $names[] = $inGroup ? $prefix . $current : $current;
                    $current = '';
                }
                // Skip "as AliasName"
                $i++;
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                // Skip the alias name token
                if ($i < $count && is_array($tokens[$i]) && ($tokens[$i][0] === T_STRING || $tokens[$i][0] === T_NAME_QUALIFIED)) {
                    $i++;
                }

                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_STRING || $token[0] === T_NS_SEPARATOR) {
                    $current .= $token[1];
                }
                // Skip whitespace
            }

            $i++;
        }

        // Final name (non-grouped use)
        if ($current !== '') {
            $names[] = $inGroup ? $prefix . $current : $current;
        }

        return $names;
    }
}
