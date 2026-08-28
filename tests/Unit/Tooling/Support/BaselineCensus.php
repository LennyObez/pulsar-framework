<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use JsonException;
use RuntimeException;

use function array_map;
use function array_sum;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function preg_match_all;
use function sprintf;
use function str_ends_with;

use const JSON_THROW_ON_ERROR;

/**
 * Counts how many findings a suppression baseline is currently hiding.
 *
 * A baseline is a promise that a number goes down. Nothing in this repository ever
 * read the number, so the promise was unobservable: an entry added to silence a new
 * PHPStan error, or a class-shape finding recorded rather than fixed, changed the
 * file and nothing else. That is ADR-0041's thesis in its purest form — a control
 * that reports itself implemented because a file exists.
 *
 * The count is of FINDINGS, not of entries, because the two differ. A PHPStan
 * baseline block carries `count: 5`, so one new suppression can hide five errors
 * while leaving the entry count unchanged; summing `count:` is the only figure that
 * moves when the suppression widens.
 *
 * Deliberately narrow: this understands the two formats the analysis gates use and
 * refuses anything else rather than guessing. A baseline it cannot count is a
 * baseline it must not report as small.
 */
final class BaselineCensus
{
    /**
     * Findings currently suppressed by the baseline at $path.
     *
     * @throws RuntimeException when the file is missing, unreadable, or in a format
     *                          this cannot count — never a zero, because an
     *                          uncountable baseline reported as empty is exactly the
     *                          silence the ratchet exists to break.
     */
    public static function suppressedFindings(string $path): int
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No baseline at %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unreadable baseline at %s', $path));
        }

        if (str_ends_with($path, '.neon')) {
            return self::countNeon($contents, $path);
        }

        if (str_ends_with($path, '.json')) {
            return self::countJson($contents, $path);
        }

        throw new RuntimeException(sprintf(
            'Unknown baseline format: %s. Teach BaselineCensus to count it rather than leaving it '
            . 'outside the ratchet.',
            $path,
        ));
    }

    /**
     * PHPStan's baseline: `ignoreErrors:` blocks, each with a `count:` of how many
     * occurrences it hides.
     */
    private static function countNeon(string $contents, string $path): int
    {
        $blocks = preg_match_all('/^\s*message:/m', $contents);
        $counts = preg_match_all('/^\s*count:\s*(\d+)\s*$/m', $contents, $matches);

        if ($blocks === false || $counts === false) {
            throw new RuntimeException(sprintf('Could not scan %s', $path));
        }

        if ($blocks !== $counts) {
            throw new RuntimeException(sprintf(
                '%s has %d message entries but %d count fields. A block without a count hides an '
                . 'unknown number of errors, so the total cannot be trusted.',
                $path,
                $blocks,
                $counts,
            ));
        }

        return array_sum(array_map(static fn(string $count): int => (int) $count, $matches[1]));
    }

    /**
     * The class-shape baseline: a JSON document whose `entries` list is one string
     * per recorded finding.
     */
    private static function countJson(string $contents, string $path): int
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, $exception->getMessage()));
        }

        if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
            throw new RuntimeException(sprintf(
                '%s has no `entries` list, so there is nothing to count. An empty result here would '
                . 'read as a perfectly clean baseline.',
                $path,
            ));
        }

        return count($decoded['entries']);
    }
}
