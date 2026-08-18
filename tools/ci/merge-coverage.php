<?php

declare(strict_types=1);

/**
 * Fold the per-shard coverage reports back into one Clover file.
 *
 * The suite's path coverage takes 10.6 hours in one process — past GitHub's own
 * six-hour job ceiling, so no timeout value can accommodate it. It is therefore
 * measured in shards and reassembled here.
 *
 * Merging cannot inflate a percentage. Every shard instruments the identical
 * <source> set from phpunit.xml and reports uncovered files as well as covered
 * ones, so each shard's report already carries the whole denominator; the merge
 * only unions the numerators. A lost shard lowers the figure and trips the gate.
 * It cannot raise it.
 *
 * Merger refuses reports from a different PHP build, a different coverage driver
 * or a different commit, so a matrix leg that drifted is rejected rather than
 * averaged in.
 *
 * Usage:
 *   php tools/ci/merge-coverage.php <output.xml> <shard.cov> [shard.cov ...]
 */

use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$target = $argv[1] ?? '';

// Non-empty strings, because Merger::merge() takes iterable<non-empty-string> and
// an empty argument would otherwise reach it as a path to nowhere.
$paths = array_values(array_filter(
    array_slice($argv ?? [], 2),
    static fn(string $path): bool => $path !== '',
));

if ($target === '' || $paths === []) {
    fwrite(STDERR, "Usage: php tools/ci/merge-coverage.php <output.xml> <shard.cov> [shard.cov ...]\n");

    exit(2);
}

$missing = array_values(array_filter($paths, static fn(string $p): bool => !is_file($p)));

if ($missing !== []) {
    // Never merge a partial set: fewer shards means fewer covered lines against
    // the same denominator, which reads as a coverage regression rather than as
    // the infrastructure failure it is.
    fwrite(STDERR, sprintf(
        "Refusing to merge an incomplete set — %d shard report(s) missing:\n  %s\n",
        count($missing),
        implode("\n  ", $missing),
    ));

    exit(1);
}

printf("Merging %d shard report(s)…\n", count($paths));

$merged = new Merger()->merge($paths);

$directory = dirname($target);

if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
    fwrite(STDERR, sprintf("Could not create \"%s\".\n", $directory));

    exit(1);
}

Facade::fromSerializedData($merged)->renderClover($target, 'Pulsar');

printf("Clover written: %s (%d bytes)\n", $target, (int) filesize($target));
