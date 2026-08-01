<?php

declare(strict_types=1);

/**
 * Benchmark worker — spawned as a child process by run.php.
 *
 * Boots the Pulsar kernel, measures cold-boot time and per-request latency,
 * then outputs a single JSON line to stdout.
 *
 * This file is for benchmarking only — not a production entry point.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

$warmupIterations = 200;
$measuredIterations = 1000;

// Cold boot measurement
$bootStart = hrtime(true);
$kernel = new Kernel();
$kernel->router()->get('/bench', fn () => Response::text('ok'));
$kernel->boot();
$bootUs = (int) ((hrtime(true) - $bootStart) / 1_000);

$request = new ServerRequest(
    method: 'GET',
    uri: '/bench',
);

// Warmup (not measured)
for ($i = 0; $i < $warmupIterations; $i++) {
    $kernel->handle($request);
}

// Measured iterations
$timings = [];

for ($i = 0; $i < $measuredIterations; $i++) {
    $start = hrtime(true);
    $kernel->handle($request);
    $timings[] = (int) ((hrtime(true) - $start) / 1_000); // microseconds
}

sort($timings);

$p50 = $timings[(int) (count($timings) * 0.50)];
$p95 = $timings[(int) (count($timings) * 0.95)];

$totalUs = array_sum($timings);
$rps = $totalUs > 0 ? (int) ($measuredIterations / ($totalUs / 1_000_000)) : 0;

$peakRssKb = (int) (memory_get_peak_usage(true) / 1024);
$memoryUsageKb = (int) (memory_get_usage(true) / 1024);

// OPcache shared memory usage (null if unavailable)
$opcacheMemoryKb = null;

if (function_exists('opcache_get_status')) {
    $opcacheStatus = opcache_get_status(false);
    $memoryUsage = is_array($opcacheStatus) ? ($opcacheStatus['memory_usage'] ?? null) : null;
    $usedMemory = is_array($memoryUsage) ? ($memoryUsage['used_memory'] ?? null) : null;

    // isset() proves the key is there, not that it holds a number: opcache_get_status()
    // is typed as array<mixed> and a non-numeric value would divide as 0 silently.
    if (is_int($usedMemory) || is_float($usedMemory)) {
        $opcacheMemoryKb = (int) ($usedMemory / 1024);
    }
}

// Warm boot measurement (5 cycles with warm OPcache/JIT)
unset($kernel);
$warmBootTimings = [];

for ($w = 0; $w < 5; $w++) {
    $wStart = hrtime(true);
    $wKernel = new Kernel();
    $wKernel->router()->get('/bench', fn () => Response::text('ok'));
    $wKernel->boot();
    $warmBootTimings[] = (int) ((hrtime(true) - $wStart) / 1_000);
    unset($wKernel);
}

$warmBootUs = (int) (array_sum($warmBootTimings) / count($warmBootTimings));

$result = [
    'boot_us' => $bootUs,
    'iterations' => $measuredIterations,
    'memory_usage_kb' => $memoryUsageKb,
    'opcache_memory_kb' => $opcacheMemoryKb,
    'p50_us' => $p50,
    'p95_us' => $p95,
    'peak_rss_kb' => $peakRssKb,
    'rps' => $rps,
    'warm_boot_us' => $warmBootUs,
];

ksort($result);

echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
