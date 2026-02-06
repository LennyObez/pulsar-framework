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
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

$warmupIterations = 200;
$measuredIterations = 1000;

// Cold boot measurement
$bootStart = hrtime(true);
$kernel = new Kernel();
$kernel->router()->get('/bench', fn () => Response::text('ok'));
$kernel->boot();
$bootUs = (int) ((hrtime(true) - $bootStart) / 1_000);

$request = new Request(
    method: Method::GET,
    uri: '/bench',
    path: '/bench',
    queryString: '',
    headers: new HeaderBag(),
    body: '',
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

$result = [
    'boot_us' => $bootUs,
    'iterations' => $measuredIterations,
    'p50_us' => $p50,
    'p95_us' => $p95,
    'peak_rss_kb' => $peakRssKb,
    'rps' => $rps,
];

ksort($result);

echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
