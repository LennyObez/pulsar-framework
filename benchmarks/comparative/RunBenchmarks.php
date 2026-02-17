<?php

declare(strict_types=1);

/**
 * Benchmark runner that measures Pulsar against baseline results.
 *
 * Compares current performance against stored baselines and reports
 * regressions. Used by CI to gate PRs that degrade performance by >5%.
 *
 * Usage:
 *   php benchmarks/comparative/RunBenchmarks.php [--baseline=tools/php/benchmark-baseline.json] [--update-baseline] [--threshold=5]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Pulsar\Benchmark\PulsarBench;

$options = getopt('', ['baseline:', 'update-baseline', 'threshold:']);

$baselinePath = $options['baseline'] ?? __DIR__ . '/../../tools/php/benchmark-baseline.json';
$updateBaseline = isset($options['update-baseline']);
$threshold = (float) ($options['threshold'] ?? 5.0);

// Run benchmarks in-process and collect results as JSON
$bench = new PulsarBench(
    iterations: 5000,
    warmup: 200,
    jsonOutput: true,
);

ob_start();
$bench->run();
$jsonOutput = ob_get_clean();

$currentResults = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);

if ($updateBaseline) {
    $dir = dirname($baselinePath);

    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    file_put_contents($baselinePath, json_encode($currentResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fprintf(STDOUT, "Baseline updated at %s\n", $baselinePath);
    exit(0);
}

// Compare against baseline
if (!is_file($baselinePath)) {
    fwrite(STDERR, "No baseline found at {$baselinePath}. Run with --update-baseline first.\n");
    echo json_encode($currentResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);

$regressions = [];
$improvements = [];

echo "\nPerformance Comparison vs Baseline\n";
echo str_repeat('=', 70) . "\n";
echo sprintf("%-30s %12s %12s %8s %6s\n", 'Benchmark', 'Baseline', 'Current', 'Change', 'Status');
echo str_repeat('-', 70) . "\n";

foreach ($currentResults as $name => $current) {
    if (!isset($baseline[$name])) {
        echo sprintf("%-30s %12s %12s %8s %6s\n", $name, 'N/A', number_format($current['ops_per_sec']), 'NEW', 'OK');
        continue;
    }

    $baseOps = $baseline[$name]['ops_per_sec'];
    $currentOps = $current['ops_per_sec'];

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
