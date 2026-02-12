<?php

declare(strict_types=1);

/**
 * Router script for the Studio development server (php -S).
 *
 * This script bootstraps a minimal Studio environment and dispatches
 * requests to the StudioRouter. Static assets are served from the
 * filesystem; all other routes are handled by controllers.
 *
 * Usage: php -S host:port -t extensions/studio/dev/public/ extensions/studio/dev/router.php
 */

// Find project root by locating vendor/autoload.php
$dir = __DIR__;
while ($dir !== dirname($dir)) {
    if (file_exists($dir . '/vendor/autoload.php')) {
        break;
    }
    $dir = dirname($dir);
}

require $dir . '/vendor/autoload.php';

use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Extension\Studio\Server\Controller\ApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkController;
use Pulsar\Extension\Studio\Server\Controller\ConsoleOverviewController;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Extension\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Extension\Studio\Server\Controller\LandingController;
use Pulsar\Extension\Studio\Server\Controller\LogExplorerController;
use Pulsar\Extension\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;
use Pulsar\Extension\Studio\Server\StudioRouter;
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// Serve static assets under /studio/assets/
$assetPrefix = '/studio/assets/';
if (str_starts_with($path, $assetPrefix)) {
    $assetPath = substr($path, strlen($assetPrefix));

    // CSS: extensions/studio/frontend/styles/
    // JS:  extensions/studio/frontend/dist/
    $candidates = [
        $dir . '/extensions/studio/frontend/styles/' . $assetPath,
        $dir . '/extensions/studio/frontend/dist/' . $assetPath,
    ];

    $mimeTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
    ];

    foreach ($candidates as $filePath) {
        $realPath = realpath($filePath);
        if ($realPath !== false && is_file($realPath)) {
            $ext = pathinfo($realPath, PATHINFO_EXTENSION);
            header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
            header('Cache-Control: no-cache');
            readfile($realPath);

            return;
        }
    }

    http_response_code(404);
    echo 'Asset not found';

    return;
}

// Short-circuit browser probe paths (Chrome DevTools, favicon, etc.)
if (str_starts_with($path, '/.well-known/') || $path === '/favicon.ico') {
    http_response_code(404);

    return;
}

// Let the built-in server handle files that exist in the document root
if ($path !== '/' && file_exists(__DIR__ . '/public' . $path)) {
    return false;
}

// Bootstrap Studio for dynamic routes
$envFile = file_exists($dir . '/.env') ? $dir . '/.env' : null;
$environment = Environment::load($envFile);

$configPath = $dir . '/config/studio.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Studio config not found. Create config/studio.php to use Studio.';

    return;
}

/** @var array<string, mixed> $configData */
$configData = require $configPath;
$config = StudioConfig::fromArray($configData, $environment);

if (!$config->enabled) {
    http_response_code(503);
    echo 'Studio is disabled. Set enabled: true in config/studio.php.';

    return;
}

// Create event store (read-only access for the server)
$storagePath = str_starts_with($config->storagePath, '/')
    ? $config->storagePath
    : $dir . '/' . $config->storagePath;

// Ensure storage directory exists
$storageDir = dirname($storagePath);
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0o755, true);
}

$sqliteStore = new SqliteEventStore($storagePath);

// Wrap with encryption if PULSAR_MASTER_KEY is set (must match Kernel's sub-key derivation)
$store = $sqliteStore;
$masterKeyHex = $environment->get('PULSAR_MASTER_KEY') ?? '';
if ($masterKeyHex !== '') {
    try {
        $masterKey = MasterKey::fromHex($masterKeyHex);
        $studioEncryptor = Encryptor::fromDerivedKey($masterKey, 3, 'stud_enc');
        $store = new EncryptedEventStore($sqliteStore, $studioEncryptor);
    } catch (Throwable) {
        // Invalid key or sodium failure — use plain store
    }
}

// Create dependencies
$aggregator = new DashboardAggregator($store);
$timelineBuilder = new TimelineBuilder($store);

$envMode = EnvironmentMode::tryFrom($environment->get('APP_ENV') ?? 'local') ?? EnvironmentMode::Local;
$safetyMode = new ProductionSafetyMode($envMode);

// Create controllers
$landing = new LandingController($config, $store);
$consoleOverview = new ConsoleOverviewController($aggregator);
$requestExplorer = new RequestExplorerController($store);
$databaseExplorer = new DatabaseExplorerController($store);
$logExplorer = new LogExplorerController($store);
$exceptionExplorer = new ExceptionExplorerController($store, $safetyMode);
$timeline = new TimelineController($timelineBuilder);
$api = new ApiController($store);
$benchmarkController = new BenchmarkController($aggregator);
$benchmarkApi = new BenchmarkApiController($store, $dir);

// Create router and dispatch
$router = new StudioRouter(
    landing: $landing,
    consoleOverview: $consoleOverview,
    requestExplorer: $requestExplorer,
    databaseExplorer: $databaseExplorer,
    logExplorer: $logExplorer,
    exceptionExplorer: $exceptionExplorer,
    timeline: $timeline,
    api: $api,
    benchmark: $benchmarkController,
    benchmarkApi: $benchmarkApi,
    safetyMode: $safetyMode,
);

// Build ServerRequest from globals with _query_ attributes
/** @var array<string, mixed> $queryParams */
$queryParams = $_GET;
$queryAttributes = [];
foreach ($queryParams as $key => $value) {
    if (is_string($value)) {
        $queryAttributes['_query_' . $key] = $value;
    }
}

$request = ServerRequest::fromGlobals();
foreach ($queryAttributes as $attrKey => $attrValue) {
    $request = $request->withAttribute($attrKey, $attrValue);
}

try {
    $response = $router->dispatch($request);
} catch (JsonException $e) {
    http_response_code(500);
    echo 'Internal Server Error: ' . $e->getMessage();

    return;
}

// Emit response
http_response_code($response->getStatusCode());

foreach ($response->getHeaders() as $name => $values) {
    $first = true;
    foreach ($values as $value) {
        header($name . ': ' . $value, $first);
        $first = false;
    }
}

echo (string) $response->getBody();
