<?php

declare(strict_types=1);

/**
 * Benchmark runner that measures Pulsar against baseline results.
 *
 * Compares current performance against stored baselines and reports
 * regressions. Used by CI to gate PRs that degrade performance by >5%.
 *
 * Every input is validated before it is compared. This is a gate: a baseline
 * file that decoded to an unexpected shape used to flow straight into the
 * arithmetic, where a missing `ops_per_sec` reads as null, a comparison against
 * null passes, and a real regression ships. Failing loudly on a malformed
 * baseline is the only useful behaviour.
 *
 * Usage:
 *   php benchmarks/Comparative/RunBenchmarks.php [--baseline=tools/php/benchmark-baseline.json] [--update-baseline] [--threshold=5]
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

        // The committed baseline carries an `_comment` key explaining how to
        // regenerate it; underscore-prefixed keys are metadata, not measurements.
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

$options = getopt('', ['baseline:', 'update-baseline', 'threshold:']);

if ($options === false) {
    fwrite(STDERR, "Could not parse command-line options.\n");

    exit(1);
}

$baselinePath = $stringOption($options, 'baseline', __DIR__ . '/../../tools/php/benchmark-baseline.json');
$updateBaseline = isset($options['update-baseline']);
$threshold = (float) $stringOption($options, 'threshold', '5.0');

// Run benchmarks in-process and collect results as JSON
$bench = new PulsarBench(
    iterations: 5000,
    warmup: 200,
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
    fwrite(STDERR, "No baseline found at {$baselinePath}. Run with --update-baseline first.\n");
    echo json_encode($asBaselineDocument($currentResults), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit(0);
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

echo "\nPerformance Comparison vs Baseline\n";
echo str_repeat('=', 70) . "\n";
echo sprintf("%-30s %12s %12s %8s %6s\n", 'Benchmark', 'Baseline', 'Current', 'Change', 'Status');
echo str_repeat('-', 70) . "\n";

foreach ($currentResults as $name => $currentOps) {
    if (!isset($baseline[$name])) {
        echo sprintf("%-30s %12s %12s %8s %6s\n", $name, 'N/A', number_format($currentOps), 'NEW', 'OK');

        continue;
    }

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
