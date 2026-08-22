<?php

declare(strict_types=1);

/**
 * Public API Snapshot Generator
 *
 * Scans src/ for classes annotated with #[Api] or #[Internal] and produces
 * a deterministic JSON snapshot at tools/api/public-api.snapshot.json.
 *
 * The scan itself lives in {@see \Pulsar\Tooling\Api\ApiSnapshotBuilder} so this
 * script and the test that re-verifies the committed file cannot drift apart.
 *
 * Usage: php tools/api/generate-snapshot.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Pulsar\Extensibility\ExtensionAutoloader;
use Pulsar\Tooling\Api\ApiSnapshotBuilder;

// Some core classes implement extension #[Api] interfaces (e.g. src/Live/Admin,
// src/Deploy/Check, src/Database/Migration). Those extension classes are no
// longer in the root composer autoload (ADR-0004), so register extension PSR-4
// autoloading before reflecting src/ — otherwise ReflectionClass on those core
// classes fatals on the unresolvable interface.
ExtensionAutoloader::registerForPaths([__DIR__ . '/../../extensions']);

$outputPath = __DIR__ . '/public-api.snapshot.json';

$snapshot = new ApiSnapshotBuilder(__DIR__ . '/../../src')->build();
$counts = ApiSnapshotBuilder::counts($snapshot);

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($outputPath, $json);

echo "Snapshot generated: {$outputPath}\n";
echo "  API classes: {$counts['api']}\n";
echo "  Internal classes: {$counts['internal']}\n";
