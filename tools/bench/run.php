<?php

declare(strict_types=1);

/**
 * Pulsar Performance Profile Benchmark Runner
 *
 * Spawns fresh PHP processes for each OPcache/JIT/preload combination
 * and collects timing metrics for comparison.
 *
 * Temporary preload files generated for benchmarking are cleaned up after
 * the run. This is a development-only tool — not for production use.
 * For production preload, use: php bin/pulsar preload:dump --output=preload.generated.php
 *
 * Usage: php tools/bench/run.php [--output=var/bench/results.json]
 */

use Pulsar\Support\AtomicFileWriter;
use Pulsar\Tooling\Support\JsonDocument;
use Pulsar\Tooling\Support\SampleStatistics;

$basePath = dirname(__DIR__, 2);

require $basePath . '/vendor/autoload.php';

$outputPath = $basePath . '/var/bench/results.json';

// A single sample per profile cannot separate a real difference from noise: two
// runs of this matrix disagreed on the SIGN of the baseline/baseline-optimized
// delta. ci.yml already claims "warmup + statistical thresholds" as the reason it
// lets this gate block a GA release — the claim was simply not implemented.
$repeat = 5;

// A profile whose retained samples spread more than this cannot support a verdict:
// the machine was not quiet enough to measure on. Certifying a release against such
// a sample is worse than not measuring, because the number still looks like a fact.
$maxSpread = 5.0;

// How far a median may fall below the baseline before it counts as a regression.
$regressionThreshold = 10.0;
$check = false;
$updateBaseline = false;
$baselineFile = __DIR__ . '/profile-baseline.json';

// Parse --output and --repeat from argv
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
    }

    if (str_starts_with($arg, '--repeat=')) {
        $repeat = max(1, (int) substr($arg, 9));
    }

    if (str_starts_with($arg, '--max-spread=')) {
        $maxSpread = (float) substr($arg, 13);
    }

    if (str_starts_with($arg, '--threshold=')) {
        $regressionThreshold = (float) substr($arg, 12);
    }

    if ($arg === '--check') {
        $check = true;
    }

    if ($arg === '--update-baseline') {
        $updateBaseline = true;
    }
}

$median = SampleStatistics::median(...);
$spreadPercent = SampleStatistics::spreadPercent(...);

// Load profiles
$profilesFile = __DIR__ . '/profiles.json';

if (!file_exists($profilesFile)) {
    fwrite(STDERR, "Error: profiles.json not found at $profilesFile\n");
    exit(1);
}

// Validated rather than asserted with an inline @var: the profile matrix drives
// which php -d flags each worker runs under, so a profile whose `ini` decoded to
// something other than a string map would silently benchmark the wrong settings
// and report the result under the profile's name anyway.
/** @var array<string, array{ini: array<string, string>, preload: bool, optimize: bool, worker: string, description: string}> $profiles */
$profiles = [];

foreach (JsonDocument::fromFile($profilesFile)->documents() as $profileName => $profileDocument) {
    $profiles[$profileName] = [
        'ini' => $profileDocument->stringMap('ini'),
        'preload' => $profileDocument->boolOr('preload', false),
        'optimize' => $profileDocument->boolOr('optimize', false),
        // Six profiles ask for the persistent-runtime worker. run.php hardcoded
        // worker.php, so those six measured the standard worker and published the
        // numbers under runtime-* names: not duplicate rows this time, mislabelled
        // ones, which is the harder kind to notice.
        'worker' => $profileDocument->stringOr('worker', 'standard'),
        'description' => $profileDocument->stringOr('description', ''),
    ];
}

// Detect PHP binary
$phpBinary = PHP_BINARY;
$workerScripts = [
    'standard' => __DIR__ . '/worker.php',
    'runtime' => __DIR__ . '/runtime-worker.php',
];

echo "Pulsar Performance Profile Matrix\n";
echo sprintf("PHP %s (%s) | %s %s\n", PHP_VERSION, PHP_SAPI, PHP_OS_FAMILY, php_uname('m'));
echo str_repeat('=', 70) . "\n\n";

$results = [];
$tempPreloadFile = null;

// Generate temporary preload for preload-enabled profiles
$needsPreload = false;

foreach ($profiles as $profile) {
    if ($profile['preload']) {
        $needsPreload = true;
        break;
    }
}

if ($needsPreload) {
    $tempPreloadFile = sys_get_temp_dir() . '/pulsar_bench_preload_' . bin2hex(random_bytes(4)) . '.php';

    echo "Generating temporary preload script for benchmarking...\n";
    echo "  (This is a dev-only artifact, cleaned up after the run.)\n\n";

    $preloadCmd = sprintf(
        '%s %s/bin/pulsar preload:dump --output=%s --no-meta 2>&1',
        escapeshellarg($phpBinary),
        escapeshellarg($basePath),
        escapeshellarg($tempPreloadFile),
    );

    exec($preloadCmd, $preloadOutput, $preloadExitCode);

    if ($preloadExitCode !== 0) {
        fwrite(STDERR, "Warning: preload:dump failed (exit $preloadExitCode). Preload profiles will be skipped.\n");
        fwrite(STDERR, implode("\n", $preloadOutput) . "\n");
        $tempPreloadFile = null;
    }
}

// Header
printf(
    "%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n",
    'Profile',
    'Boot (us)',
    'Warm (us)',
    'p50 (us)',
    'p95 (us)',
    'RPS',
    'Alloc (KB)',
    'RSS (KB)',
    'OPC (KB)',
);
echo str_repeat('-', 25) . '-|' . str_repeat('-', 11) . '|' . str_repeat('-', 11)
    . '|' . str_repeat('-', 10) . '|' . str_repeat('-', 10) . '|' . str_repeat('-', 9)
    . '|' . str_repeat('-', 12) . '|' . str_repeat('-', 10) . '|' . str_repeat('-', 10) . "\n";

// Run profiles in sorted order for deterministic output
ksort($profiles);

foreach ($profiles as $name => $profile) {
    // Skip preload profiles if preload generation failed
    if ($profile['preload'] && $tempPreloadFile === null) {
        printf("%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n", $name, 'skipped', '-', '-', '-', '-', '-', '-', '-');
        continue;
    }

    // Build -d flags
    $iniFlags = [];

    foreach ($profile['ini'] as $key => $value) {
        $iniFlags[] = '-d';
        $iniFlags[] = sprintf('%s=%s', $key, $value);
    }

    if ($profile['preload'] && $tempPreloadFile !== null) {
        $iniFlags[] = '-d';
        $iniFlags[] = sprintf('opcache.preload=%s', $tempPreloadFile);
    }

    $workerScript = $workerScripts[$profile['worker']] ?? null;

    if ($workerScript === null) {
        // Naming a worker that does not exist must not silently fall back to the
        // default one — that is how six profiles came to measure the wrong thing.
        printf(
            "%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n",
            $name,
            'no-worker',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
        );
        $results[$name] = ['error' => sprintf('Unknown worker "%s"', $profile['worker'])];

        continue;
    }

    $cmd = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg($phpBinary),
        implode(' ', array_map('escapeshellarg', $iniFlags)),
        escapeshellarg($workerScript),
    );

    // Honour the profile's `optimize` flag. Every profile declares it and six set it
    // to true, but nothing read it: the "-optimized" rows measured exactly what
    // their plain twins did, and the matrix presented the duplicates as distinct
    // results. Warming or clearing the framework cache (config, routes, container)
    // is what the flag was always meant to select.
    $optimizeExit = 0;
    $optimizeOutput = [];
    exec(
        sprintf(
            '%s %s %s 2>&1',
            escapeshellarg($phpBinary),
            escapeshellarg($basePath . '/bin/pulsar'),
            escapeshellarg($profile['optimize'] ? 'optimize' : 'optimize:clear'),
        ),
        $optimizeOutput,
        $optimizeExit,
    );

    // Trusting the exit code is not enough. `optimize:validate` looks like the tool
    // for this and is not: it re-runs OptimizeCommand internally, so calling it after
    // optimize:clear would warm the cache again and quietly destroy the very
    // comparison this flag exists to draw. The state itself is what must be checked.
    $cacheEntries = glob($basePath . '/var/cache/framework/*.cache.bin');
    $cacheIsWarm = $cacheEntries !== false && $cacheEntries !== [];

    if ($optimizeExit === 0 && $cacheIsWarm !== $profile['optimize']) {
        printf(
            "%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n",
            $name,
            $profile['optimize'] ? 'not-warm' : 'not-cold',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
        );
        $results[$name] = ['error' => sprintf(
            'framework cache is %s but the profile asked for optimize=%s',
            $cacheIsWarm ? 'warm' : 'cold',
            $profile['optimize'] ? 'true' : 'false',
        )];

        continue;
    }

    if ($optimizeExit !== 0) {
        // Reporting a number here would be worse than reporting nothing: the row
        // would look like a measurement of the optimised path while being one of
        // the unoptimised path. `optimize` needs PULSAR_MASTER_KEY, so this is a
        // realistic outcome on a bare checkout.
        printf(
            "%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n",
            $name,
            $profile['optimize'] ? 'no-cache' : 'no-clear',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
            '-',
        );

        continue;
    }

    // Sample the profile $repeat times. The first sample is discarded when there is
    // more than one: it pays for a cold OS file cache and a cold OPcache, costs that
    // belong to the machine rather than to the configuration under test.
    /** @var list<JsonDocument> $samples */
    $samples = [];
    $output = [];
    $exitCode = 0;

    for ($run = 0; $run < $repeat; $run++) {
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            break;
        }

        try {
            $samples[] = JsonDocument::fromString((string) end($output), $name . ' sample ' . $run);
        } catch (RuntimeException) {
            break;
        }
    }

    if (count($samples) > 1) {
        array_shift($samples);
    }

    if ($exitCode !== 0 || $output === []) {
        printf("%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n", $name, 'error', '-', '-', '-', '-', '-', '-', '-');
        $results[$name] = ['error' => implode("\n", $output)];
        continue;
    }

    if ($samples === []) {
        printf("%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n", $name, 'parse-err', '-', '-', '-', '-', '-', '-', '-');
        $results[$name] = ['error' => 'no parsable sample: ' . implode("\n", $output)];

        continue;
    }

    // Median of each metric across the retained samples. A mean would let one
    // descheduled run invent a regression; the median ignores it.
    $column = static fn(string $key): array => array_values(array_map(
        static fn(JsonDocument $sample): int => $sample->intOr($key, 0),
        $samples,
    ));

    $rpsSamples = $column('rps');
    $metrics = [
        'boot_us' => $median($column('boot_us')),
        'warm_boot_us' => $median($column('warm_boot_us')),
        'p50_us' => $median($column('p50_us')),
        'p95_us' => $median($column('p95_us')),
        'rps' => $median($rpsSamples),
        'peak_rss_kb' => $median($column('peak_rss_kb')),
        'memory_usage_kb' => $median($column('memory_usage_kb')),
        // Not a median: OPcache's consumed memory is a property of the configuration,
        // not a timing that varies run to run. Null means the profile runs without
        // OPcache, which prints as '-' rather than as a zero it never measured.
        'opcache_memory_kb' => $samples[0]->intOrNull('opcache_memory_kb'),
    ];

    $opcacheMemoryKb = $metrics['opcache_memory_kb'];
    $opcacheDisplay = $opcacheMemoryKb !== null ? number_format($opcacheMemoryKb) : '-';

    printf(
        "%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n",
        $name,
        number_format($metrics['boot_us']),
        number_format($metrics['warm_boot_us']),
        number_format($metrics['p50_us']),
        number_format($metrics['p95_us']),
        number_format($metrics['rps']),
        number_format($metrics['memory_usage_kb']),
        number_format($metrics['peak_rss_kb']),
        $opcacheDisplay,
    );

    // The description is what tells a reader of results.json what a profile was
    // for; leaving it in the manifest and nowhere else made it decoration.
    $results[$name] = $metrics + [
        // Published so a reader can tell a 2% gap between profiles from a 20%
        // wobble inside one. Without it, every figure invites over-reading.
        'samples' => count($samples),
        'rps_spread_percent' => $spreadPercent($rpsSamples),
        'description' => $profile['description'],
        'worker' => $profile['worker'],
        'optimize' => $profile['optimize'],
    ];
}

echo "\n";

// Write results atomically with stable key order
ksort($results);

foreach ($results as &$profileResult) {
    ksort($profileResult);
}
unset($profileResult);

$json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$outputDir = dirname($outputPath);

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0o755, true);
}

AtomicFileWriter::write($outputPath, $json . "\n");
echo "Results saved to $outputPath\n\n";

// Cleanup temporary preload file
if ($tempPreloadFile !== null && file_exists($tempPreloadFile)) {
    @unlink($tempPreloadFile);
    echo "Cleaned up temporary preload script.\n";
}

echo "Note: CLI SAPI results may differ from FPM. For production-like\n";
echo "benchmarking, measure under your production SAPI configuration.\n";
echo "JIT benefits vary by workload — always measure for your specific use case.\n";

// ---------------------------------------------------------------------------
// Statistical gate
// ---------------------------------------------------------------------------
//
// ci.yml dropped continue-on-error from this step so it can block a GA release,
// justifying it with "warmup + statistical thresholds" — a mechanism that did not
// exist. Both halves are needed, and in this order: comparing medians is pointless
// without first asking whether the samples deserve to be compared at all.

if ($updateBaseline) {
    $recorded = [];

    foreach ($results as $name => $result) {
        if (isset($result['rps']) && is_int($result['rps'])) {
            $recorded[$name] = ['rps' => $result['rps']];
        }
    }

    ksort($recorded);
    AtomicFileWriter::write(
        $baselineFile,
        json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    echo "\nBaseline updated at {$baselineFile} (" . count($recorded) . " profiles)\n";

    exit(0);
}

if (!$check) {
    exit(0);
}

$noisy = [];
$regressed = [];
$compared = 0;
$baseline = is_file($baselineFile) ? JsonDocument::fromFile($baselineFile)->documents() : [];

foreach ($results as $name => $result) {
    if (!isset($result['rps'], $result['rps_spread_percent']) || !is_int($result['rps'])) {
        continue;
    }

    // A spread beyond tolerance means the machine moved under the measurement. The
    // profile is then neither slow nor fast — it is unmeasured, and saying so is the
    // only honest outcome. Exactly this happened while a six-worker test suite shared
    // the CPU: spreads reached 22% and the ranking between profiles inverted between
    // runs of the same code.
    if ((float) $result['rps_spread_percent'] > $maxSpread) {
        $noisy[] = sprintf(
            '%s (spread %.1f%% > %.1f%%)',
            $name,
            (float) $result['rps_spread_percent'],
            $maxSpread,
        );

        continue;
    }

    if (!isset($baseline[$name])) {
        continue;
    }

    $expected = $baseline[$name]->intOr('rps', 0);

    if ($expected <= 0) {
        continue;
    }

    $compared++;
    $delta = (($result['rps'] - $expected) / $expected) * 100;

    if ($delta < -$regressionThreshold) {
        $regressed[] = sprintf(
            '%s: %s -> %s RPS (%.1f%%)',
            $name,
            number_format($expected),
            number_format($result['rps']),
            $delta,
        );
    }
}

echo "\nStatistical gate\n";
echo str_repeat('-', 70) . "\n";
printf("  samples per profile  : %d (first discarded as warm-up)\n", $repeat);
printf("  spread tolerance     : %.1f%%\n", $maxSpread);
printf("  regression threshold : %.1f%%\n", $regressionThreshold);
printf("  profiles compared    : %d\n", $compared);

if ($noisy !== []) {
    echo "\nUNMEASURED — the machine was not quiet enough to certify these:\n  - "
        . implode("\n  - ", $noisy) . "\n";
    echo "\nRe-run on an idle machine, or raise --repeat. A verdict on this data would\n";
    echo "be a coin flip wearing a percentage sign.\n";

    exit(1);
}

if ($regressed !== []) {
    printf("\nREGRESSION beyond %.1f%%:\n  - %s\n", $regressionThreshold, implode("\n  - ", $regressed));

    exit(1);
}

if ($compared === 0) {
    echo "\nNo baseline to compare against. Record one with --update-baseline.\n";

    exit(0);
}

printf("\nAll %d compared profiles are within threshold, on samples tight enough to mean it.\n", $compared);

exit(0);
