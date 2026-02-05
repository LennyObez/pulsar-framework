<?php

declare(strict_types=1);

/**
 * Router script for the Studio development server (php -S).
 *
 * This script bootstraps a minimal Studio environment and dispatches
 * requests to the StudioRouter. Static assets are served from the
 * filesystem; all other routes are handled by controllers.
 *
 * Usage: php -S host:port -t resources/studio/public resources/studio/router.php
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
use Pulsar\Config\StudioConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Studio\Security\ProductionSafetyMode;
use Pulsar\Studio\Server\Controller\ApiController;
use Pulsar\Studio\Server\Controller\ConsoleOverviewController;
use Pulsar\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Studio\Server\Controller\LandingController;
use Pulsar\Studio\Server\Controller\LogExplorerController;
use Pulsar\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Studio\Server\Controller\TimelineController;
use Pulsar\Studio\Server\StudioRouter;
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// Serve static assets under /studio/assets/
$assetPrefix = '/studio/assets/';
if (str_starts_with($path, $assetPrefix)) {
    $assetPath = substr($path, strlen($assetPrefix));

    // CSS: resources/studio/styles/
    // JS:  resources/studio/dist/
    $candidates = [
        $dir . '/resources/studio/styles/' . $assetPath,
        $dir . '/resources/studio/dist/' . $assetPath,
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

$store = new SqliteEventStore($storagePath);

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
    safetyMode: $safetyMode,
);

// Build Request from globals
$method = Method::tryFrom($_SERVER['REQUEST_METHOD'] ?? 'GET') ?? Method::GET;
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$headers = new HeaderBag(getallheaders() ?: []);
$body = file_get_contents('php://input') ?: '';

$request = new Request(
    method: $method,
    uri: $requestUri,
    path: $path,
    queryString: $queryString,
    headers: $headers,
    body: $body,
);

$response = $router->dispatch($request);

// Emit response
http_response_code($response->status->value);

foreach ($response->headers as $name => $values) {
    $first = true;
    foreach ($values as $value) {
        header($name . ': ' . $value, $first);
        $first = false;
    }
}

echo $response->body;
