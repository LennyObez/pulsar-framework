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

$basePath = dirname(__DIR__, 2);

require $basePath . '/vendor/autoload.php';

use Pulsar\Support\AtomicFileWriter;

$outputPath = $basePath . '/var/bench/results.json';

// Parse --output from argv
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
    }
}

// Load profiles
$profilesFile = __DIR__ . '/profiles.json';

if (!file_exists($profilesFile)) {
    fwrite(STDERR, "Error: profiles.json not found at $profilesFile\n");
    exit(1);
}

/** @var array<string, array{description: string, ini: array<string, string>, preload: bool}> $profiles */
$profiles = json_decode(file_get_contents($profilesFile), true, 512, JSON_THROW_ON_ERROR);

// Detect PHP binary
$phpBinary = PHP_BINARY;
$workerScript = __DIR__ . '/worker.php';

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

    $cmd = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg($phpBinary),
        implode(' ', array_map('escapeshellarg', $iniFlags)),
        escapeshellarg($workerScript),
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || $output === []) {
        printf("%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n", $name, 'error', '-', '-', '-', '-', '-', '-', '-');
        $results[$name] = ['error' => implode("\n", $output)];
        continue;
    }

    // Parse the last line as JSON (worker outputs a single JSON line)
    $jsonLine = end($output);

    try {
        /** @var array{boot_us: int, warm_boot_us: int, p50_us: int, p95_us: int, rps: int, peak_rss_kb: int, memory_usage_kb: int, opcache_memory_kb: ?int} $metrics */
        $metrics = json_decode($jsonLine, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        printf("%-25s | %9s | %9s | %8s | %8s | %7s | %10s | %8s | %8s\n", $name, 'parse-err', '-', '-', '-', '-', '-', '-', '-');
        $results[$name] = ['error' => 'JSON parse failed: ' . $jsonLine];
        continue;
    }

    $opcacheDisplay = $metrics['opcache_memory_kb'] !== null
        ? number_format($metrics['opcache_memory_kb'])
        : '-';

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

    $results[$name] = $metrics;
}

echo "\n";

// Write results atomically with stable key order
ksort($results);

foreach ($results as &$profileResult) {
    if (is_array($profileResult)) {
        ksort($profileResult);
    }
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
