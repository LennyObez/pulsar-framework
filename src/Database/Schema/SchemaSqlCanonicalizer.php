<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use function array_filter;
use function array_map;
use function implode;

use Pulsar\Api\Api;

use function rtrim;
use function trim;

/**
 * Normalizes SQL statements for stable evidence hashing.
 *
 * Ensures consistent hashes regardless of whitespace variations
 * or line ending differences across environments.
 */
#[Api(since: '1.0.0')]
final class SchemaSqlCanonicalizer
{
    /**
     * Canonicalize a list of SQL statements into a stable string for hashing.
     *
     * @param list<string> $statements
     */
    public static function canonicalize(array $statements): string
    {
        $statements = array_filter($statements, static fn(string $s): bool => trim($s) !== '');

        $normalized = array_map(
            static function (string $sql): string {
                $trimmed = trim($sql);
                $lines = explode("\n", $trimmed);
                $lines = array_map(static fn(string $line): string => rtrim($line), $lines);

                return implode("\n", $lines);
            },
            $statements,
        );

        return implode("\n", $normalized);
    }
}
