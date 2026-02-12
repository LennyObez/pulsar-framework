<?php

declare(strict_types=1);

/**
 * Router script for the Admin panel development server (php -S).
 *
 * Bootstraps the full framework kernel and dispatches requests through it.
 * Admin routes, database, auth, and all extensions are available.
 *
 * Usage: php -S host:port -t . extensions/admin/dev/router.php
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

use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// Serve static assets from the admin extension frontend
$adminAssetPrefix = '/admin/assets/';
if (str_starts_with($path, $adminAssetPrefix)) {
    $assetPath = substr($path, strlen($adminAssetPrefix));

    $candidates = [
        $dir . '/extensions/admin/frontend/styles/' . $assetPath,
        $dir . '/extensions/admin/frontend/dist/' . $assetPath,
    ];

    $mimeTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
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

// Bootstrap the full framework kernel
$extensionBootstrap = null;
$extensionPaths = [
    $dir . '/extensions',
];

foreach ($extensionPaths as $extPath) {
    if (is_dir($extPath)) {
        $extensionBootstrap = ExtensionBootstrap::create();
        try {
            $extensionBootstrap->loadFromPaths([$extPath]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Extension loading failed: ' . $e->getMessage();

            return;
        }
        break;
    }
}

$configPath = $dir . '/config';
$envFile = $dir . '/.env';
$configManager = null;

if (is_dir($configPath)) {
    $configManager = new ConfigManager(
        configPath: $configPath,
        envFilePath: is_file($envFile) ? $envFile : null,
    );
}

$kernel = new Kernel(
    extensionBootstrap: $extensionBootstrap,
    configManager: $configManager,
);

// Pre-boot FrameworkCache if master key is available
$masterKeyHex = getenv('PULSAR_MASTER_KEY');
if ($masterKeyHex !== false && $masterKeyHex !== '' && $configManager !== null) {
    try {
        $earlyMasterKey = MasterKey::fromHex($masterKeyHex);
        $encrypt = getenv('CACHE_ENCRYPT') === 'true' || getenv('CACHE_ENCRYPT') === '1';
        $earlyCache = new FrameworkCache($dir, $earlyMasterKey, new HmacService(), $encrypt);
        $kernel->container()->instance(FrameworkCache::class, $earlyCache);
    } catch (Throwable) {
        // Invalid key or sodium failure — skip pre-boot cache
    }
}

try {
    $kernel->boot();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Kernel boot failed: ' . $e->getMessage();

    return;
}

// Build Request from PHP globals
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$body = file_get_contents('php://input') ?: '';

parse_str($_SERVER['QUERY_STRING'] ?? '', $queryParams);

$request = new ServerRequest(
    method: $method,
    uri: $requestUri,
    headers: $headers,
    body: $body,
    serverParams: $_SERVER,
    cookieParams: $_COOKIE,
    queryParams: $queryParams,
);

try {
    $response = $kernel->handle($request);
} catch (Throwable $e) {
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
