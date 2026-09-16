<?php

declare(strict_types=1);

/**
 * Benchmark runner that measures Pulsar against a recorded baseline.
 *
 * Used by benchmark-regression.yml to gate a pull request that degrades
 * performance by more than 5%. The baseline is recorded by that workflow from
 * the merge base, on the runner about to do the comparing, and NOT read from a
 * committed file: ops-per-second is a property of the machine that measured it,
 * so a figure recorded elsewhere is hardware noise with a threshold attached.
 * `tools/php/benchmark-baseline.json` remains the default `--baseline` path
 * because that is where `--update-baseline` writes, and .gitignore refuses it —
 * a committed copy of it held nothing but a `_comment` key for the whole
 * release-candidate phase.
 *
 * Every input is validated before it is compared, and every way of comparing
 * nothing is a refusal rather than a pass. Three of them exist:
 *
 *   - a baseline file that decoded to an unexpected shape used to flow straight
 *     into the arithmetic, where a missing `ops_per_sec` reads as null and a
 *     comparison against null passes;
 *   - a baseline path that is not there used to print the current numbers and
 *     exit 0, so a workflow with a typo in a path got a green check;
 *   - a baseline that matches no benchmark — the committed placeholder, or a
 *     baseline naming benchmarks that have since been renamed — used to print a
 *     full table of NEW/OK and exit 0.
 *
 * Usage:
 *   php benchmarks/Comparative/RunBenchmarks.php [--baseline=PATH] [--update-baseline] [--threshold=5]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Pulsar\Benchmark\Comparative\PulsarBench;

/**
 * Read a `--name=value` option, falling back when it is absent or repeated.
 *
 * getopt() returns `false` for a flag given without a value and a `list` when
 * the same option is passed more than once, so the raw value is not a string.
 *
 * @param array<string, list<string>|string|false> $options
 */
$stringOption = static function (array $options, string $name, string $default): string {
    $value = $options[$name] ?? null;

    return is_string($value) ? $value : $default;
};

/**
 * Decode a benchmark result map, rejecting anything that is not one.
 *
 * @return array<string, float> Benchmark name => operations per second
 */
$decodeResults = static function (string $json, string $source): array {
    /** @var mixed $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException("{$source} did not decode to a benchmark map.");
    }

    $results = [];

    /** @var mixed $entry */
    foreach ($decoded as $name => $entry) {
        if (!is_string($name)) {
            throw new RuntimeException("{$source} has a non-string benchmark name.");
        }

        // Underscore-prefixed keys are metadata, not measurements. The committed
        // baseline used to carry an `_comment` key explaining how to regenerate
        // it, and nothing else -- which is how a document that skipped every key
        // it held could still be read as a baseline. Skipping metadata is right;
        // what was missing is the count asserted after the comparison loop.
        if (str_starts_with($name, '_')) {
            continue;
        }

        if (!is_array($entry) || !isset($entry['ops_per_sec']) || !is_numeric($entry['ops_per_sec'])) {
            throw new RuntimeException("{$source} entry \"{$name}\" has no numeric ops_per_sec.");
        }

        $results[$name] = (float) $entry['ops_per_sec'];
    }

    return $results;
};

/**
 * Re-emit the validated map in the on-disk baseline shape.
 *
 * Validation flattens each entry to its ops_per_sec, but the file format stays
 * `{"name": {"ops_per_sec": N}}`: writing the flat map instead would produce a
 * baseline that the reader above rejects on the very next run.
 *
 * @param array<string, float> $results
 * @return array<string, array{ops_per_sec: float}>
 */
$asBaselineDocument = static function (array $results): array {
    $document = [];

    foreach ($results as $name => $opsPerSecond) {
        $document[$name] = ['ops_per_sec' => $opsPerSecond];
    }

    return $document;
};

$options = getopt('', ['baseline:', 'update-baseline', 'threshold:', 'iterations:', 'warmup:']);

if ($options === false) {
    fwrite(STDERR, "Could not parse command-line options.\n");

    exit(1);
}

$baselinePath = $stringOption($options, 'baseline', __DIR__ . '/../../tools/php/benchmark-baseline.json');
$updateBaseline = isset($options['update-baseline']);
$threshold = (float) $stringOption($options, 'threshold', '5.0');

// Run benchmarks in-process and collect results as JSON.
//
// The sample size is an option rather than a constant for one reason: a gate has
// to be exercised to be believed, and at the CI sample this script takes about
// two and a half minutes, which is more than any test can spend proving that a
// regression still fails the build. PulsarBench has carried the same two options
// since it was written; they are only forwarded here. CI passes neither and gets
// the sizes below, so what runs on a pull request is unchanged.
$bench = new PulsarBench(
    iterations: max(1, (int) $stringOption($options, 'iterations', '5000')),
    warmup: max(0, (int) $stringOption($options, 'warmup', '200')),
    jsonOutput: true,
);

ob_start();
$bench->run();
$jsonOutput = ob_get_clean();

if ($jsonOutput === false) {
    fwrite(STDERR, "Could not capture benchmark output.\n");

    exit(1);
}

$currentResults = $decodeResults($jsonOutput, 'Benchmark output');

if ($updateBaseline) {
    $dir = dirname($baselinePath);

    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents($baselinePath, json_encode($asBaselineDocument($currentResults), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fprintf(STDOUT, "Baseline updated at %s\n", $baselinePath);

    exit(0);
}

// Compare against baseline
if (!is_file($baselinePath)) {
    // Exit 1, not 0. This branch used to print the current numbers and report
    // success, so a workflow whose recording step wrote the baseline somewhere
    // else -- a renamed temp directory, a typo in the path -- got a green check
    // from a comparison that never happened. A gate handed a baseline that is not
    // there has verified nothing, and "nothing" is not "within threshold".
    fwrite(STDERR, "No baseline found at {$baselinePath}. Run with --update-baseline first.\n");
    echo json_encode($asBaselineDocument($currentResults), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit(1);
}

$baselineJson = file_get_contents($baselinePath);

if ($baselineJson === false) {
    fwrite(STDERR, "Could not read the baseline at {$baselinePath}.\n");

    exit(1);
}

$baseline = $decodeResults($baselineJson, "Baseline {$baselinePath}");

/** @var list<array{name: string, change: float, baseline: float, current: float}> $regressions */
$regressions = [];
/** @var list<array{name: string, change: float}> $improvements */
$improvements = [];

// How many benchmarks were actually measured against a recorded figure. Counted
// rather than inferred from the baseline's size, because the two differ in the
// case that matters: a baseline full of entries under names no benchmark answers
// to any more compares nothing while printing a full-width table.
$compared = 0;

echo "\nPerformance Comparison vs Baseline\n";
echo str_repeat('=', 70) . "\n";
echo sprintf("%-30s %12s %12s %8s %6s\n", 'Benchmark', 'Baseline', 'Current', 'Change', 'Status');
echo str_repeat('-', 70) . "\n";

foreach ($currentResults as $name => $currentOps) {
    if (!isset($baseline[$name])) {
        echo sprintf("%-30s %12s %12s %8s %6s\n", $name, 'N/A', number_format($currentOps), 'NEW', 'OK');

        continue;
    }

    ++$compared;
    $baseOps = $baseline[$name];

    // A zero baseline is not a 0%-change measurement, it is an unusable one:
    // dividing by it raises DivisionByZeroError and would abort the whole gate.
    if ($baseOps <= 0.0) {
        echo sprintf("%-30s %12s %12s %8s %6s\n", $name, number_format($baseOps), number_format($currentOps), 'N/A', 'SKIP');

        continue;
    }

    $changePercent = (($currentOps - $baseOps) / $baseOps) * 100;
    $changeStr = sprintf('%+.1f%%', $changePercent);

    $status = 'OK';

    if ($changePercent < -$threshold) {
        $status = 'FAIL';
        $regressions[] = ['name' => $name, 'change' => $changePercent, 'baseline' => $baseOps, 'current' => $currentOps];
    } elseif ($changePercent > $threshold) {
        $status = 'FAST';
        $improvements[] = ['name' => $name, 'change' => $changePercent];
    }

    echo sprintf(
        "%-30s %12s %12s %8s %6s\n",
        $name,
        number_format($baseOps),
        number_format($currentOps),
        $changeStr,
        $status,
    );
}

echo str_repeat('=', 70) . "\n";

// A comparison in which nothing was compared is not a comparison that passed.
//
// tools/php/benchmark-baseline.json held nothing but its own `_comment` key for
// the whole of the release-candidate phase. Every benchmark therefore printed
// NEW/OK, the table looked exactly like a comparison, and the script exited 0 --
// so benchmark-regression.yml reported a green performance check on every pull
// request while measuring nothing at all. The count is asserted here rather than
// inferred from the file being present and parseable, because a renamed benchmark
// produces the identical shape, silently, on the day of the rename.
if ($compared === 0) {
    fwrite(STDERR, sprintf(
        "Nothing was compared.\n\n"
        . 'The baseline at %s named %d benchmark(s), none of which matched the %d measured on this '
        . "run, so every row above says NEW and the exit code would have said OK.\n\n"
        . 'Either the baseline holds no measurements -- record one with --update-baseline -- or a '
        . 'benchmark has been renamed while the baseline still names the old one, in which case '
        . "re-record it in the change that renames the benchmark.\n",
        $baselinePath,
        count($baseline),
        count($currentResults),
    ));

    exit(1);
}

if ($regressions !== []) {
    echo "\nREGRESSIONS DETECTED (>{$threshold}% slower):\n";

    foreach ($regressions as $r) {
        echo sprintf(
            "  %s: %.1f%% slower (was %s ops/s, now %s ops/s)\n",
            $r['name'],
            abs($r['change']),
            number_format($r['baseline']),
            number_format($r['current']),
        );
    }

    exit(1);
}

if ($improvements !== []) {
    echo "\nImprovements:\n";

    foreach ($improvements as $imp) {
        echo sprintf("  %s: %.1f%% faster\n", $imp['name'], $imp['change']);
    }
}

echo "\nAll benchmarks within threshold. OK.\n";

exit(0);
