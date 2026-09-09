<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache\Support;

use Pulsar\Database\Cache\QueryCacheConfig;
use RuntimeException;

use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Reads the query-cache truth table out of docs/database.md and checks it.
 *
 * The finding this exists for: "caching is disabled by default in the regulated
 * preset" was stated in the class docblock, in config/database.php and in
 * docs/database.md, and implemented nowhere. Three copies of a sentence, no
 * copy of the behaviour, and nothing that could ever have noticed — the
 * documentation was not wrong about a detail, it was describing a feature that
 * did not exist.
 *
 * Building the preset fixes today. This is what stops the sentence and the code
 * from parting company again: every row of the documented table is turned back
 * into a `QueryCacheConfig::fromArray()` call and compared with what the DTO
 * actually produces. Change the default in the DTO and the table stops
 * describing it; edit the table to say something the DTO does not do and the
 * same comparison catches it. The document becomes an executable claim rather
 * than a paragraph nobody can run.
 *
 * A missing or unreadable table is a REFUSAL, never zero rows. Zero rows would
 * make every assertion below pass over nothing, which is the same defect one
 * level up: a check that cannot fail because it has nothing to check.
 */
final readonly class DocumentedQueryCacheDefaults
{
    /** Cells that mean "the operator did not write the key down at all". */
    private const array OMITTED = ['omitted', 'absent', 'not set'];

    /**
     * Every row of the documented table, as the config array it describes.
     *
     * @return list<array{line: string, config: array<string, mixed>, caching: bool}>
     *
     * @throws RuntimeException When the table is absent or a row cannot be read.
     */
    public static function rows(string $markdown): array
    {
        $rows = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $line) {
            $line = trim($line);

            if (!str_contains($line, '|') || self::isSeparator($line)) {
                continue;
            }

            $cells = self::cells($line);

            if (count($cells) !== 3) {
                continue;
            }

            $preset = self::boolCell($cells[0]);

            // The header row, and any three-column table that is not this one.
            if ($preset === null) {
                continue;
            }

            $config = ['regulated_preset' => $preset];
            $enabled = self::boolCell($cells[1]);

            if ($enabled !== null) {
                $config['enabled'] = $enabled;
            } elseif (!self::isOmitted($cells[1])) {
                throw new RuntimeException(sprintf(
                    'docs/database.md: "%s" is neither a boolean nor one of %s, so the row cannot '
                    . 'be turned into a configuration and cannot be checked against one.',
                    $cells[1],
                    '"' . implode('", "', self::OMITTED) . '"',
                ));
            }

            $caching = self::cachingCell($cells[2]);

            if ($caching === null) {
                throw new RuntimeException(sprintf(
                    'docs/database.md: "%s" does not say on or off, so the row claims nothing '
                    . 'a comparison could disagree with.',
                    $cells[2],
                ));
            }

            $rows[] = ['line' => $line, 'config' => $config, 'caching' => $caching];
        }

        if ($rows === []) {
            throw new RuntimeException(
                'docs/database.md no longer carries a `regulated_preset` truth table. Every '
                . 'assertion driven by it would now pass over an empty list, which is exactly '
                . 'the shape the table was added to close.',
            );
        }

        return $rows;
    }

    /**
     * Rows the DTO disagrees with, in the words a reviewer would use.
     *
     * @return list<string>
     *
     * @throws RuntimeException When the table is absent or a row cannot be read.
     */
    public static function violations(string $markdown): array
    {
        $violations = [];

        foreach (self::rows($markdown) as $row) {
            /** @var array{enabled?: bool, regulated_preset?: bool} $config */
            $config = $row['config'];
            $actual = QueryCacheConfig::fromArray($config)->enabled;

            if ($actual !== $row['caching']) {
                $violations[] = sprintf(
                    '%s -- documented as caching %s, QueryCacheConfig::fromArray() produces %s',
                    $row['line'],
                    $row['caching'] ? 'on' : 'off',
                    $actual ? 'on' : 'off',
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        $parts = explode('|', trim($line, '| '));

        return array_map(trim(...), $parts);
    }

    private static function isSeparator(string $line): bool
    {
        return preg_match('/^\|?[\s:|-]+\|?$/', $line) === 1;
    }

    private static function isOmitted(string $cell): bool
    {
        return in_array(strtolower(self::plain($cell)), self::OMITTED, true);
    }

    private static function boolCell(string $cell): ?bool
    {
        return match (strtolower(self::plain($cell))) {
            'true', '`true`' => true,
            'false', '`false`' => false,
            default => null,
        };
    }

    private static function cachingCell(string $cell): ?bool
    {
        return match (strtolower(self::plain($cell))) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    /**
     * Strip the markdown a cell may carry: backticks, bold, and a parenthesised
     * aside such as "`false` (default)".
     */
    private static function plain(string $cell): string
    {
        $stripped = preg_replace('/\s*\([^)]*\)\s*/', '', $cell) ?? $cell;

        return trim(str_replace(['`', '*'], '', $stripped));
    }
}
