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
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Config\CmsConfig;
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

// Ensure a PULSAR_MASTER_KEY is available for dev (required by crypto service chains).
// If none is set in the environment or .env, generate a random one for this dev session.
if (getenv('PULSAR_MASTER_KEY') === false || getenv('PULSAR_MASTER_KEY') === '') {
    putenv('PULSAR_MASTER_KEY=' . bin2hex(random_bytes(32)));
}

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

// Pre-register CmsConfig with all sub-modules enabled for dev.
$kernel->container()->instance(CmsConfig::class, new CmsConfig(
    editorialWorkflow: true,
    eventSourcing: true,
    atomicSnapshots: true,
    commerce: new CommerceConfig(),
));

try {
    $kernel->boot();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Kernel boot failed: ' . $e->getMessage();

    return;
}

// Auto-migrate all extension tables if a database is configured but schema is missing.
// This ensures zero-configuration dev experience: `admin:serve` works immediately
// without requiring a manual `migrate:run` step.
$container = $kernel->container();
if ($container->has(ConnectionInterface::class)) {
    /** @var ConnectionInterface $dbConnection */
    $dbConnection = $container->get(ConnectionInterface::class);

    $markerFile = $dir . '/storage/.admin-schema-ready';
    if (!file_exists($markerFile)) {
        // Create auth_users table (framework auth table required by admin user management)
        try {
            $dbConnection->execute(<<<'SQL'
                CREATE TABLE IF NOT EXISTS auth_users (
                    id VARCHAR(36) NOT NULL PRIMARY KEY,
                    tenant_id VARCHAR(36) DEFAULT NULL,
                    display_name VARCHAR(200) NOT NULL DEFAULT '',
                    email VARCHAR(320) DEFAULT NULL,
                    roles TEXT NOT NULL DEFAULT '[]',
                    two_factor_status VARCHAR(20) NOT NULL DEFAULT 'disabled',
                    last_active_at TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    is_locked INTEGER NOT NULL DEFAULT 0
                )
                SQL);
        } catch (Throwable $authMigrationError) {
            error_log('[Admin Dev] auth_users table creation failed: ' . $authMigrationError->getMessage());
        }

        // Run migrations from all extensions
        $extensionMigrationDirs = glob($dir . '/extensions/*/src/Migration');
        $totalMigrations = 0;

        if ($extensionMigrationDirs !== false) {
            foreach ($extensionMigrationDirs as $migrationDir) {
                $migrationFiles = scandir($migrationDir);
                if ($migrationFiles === false) {
                    continue;
                }

                sort($migrationFiles);

                foreach ($migrationFiles as $migrationFile) {
                    if (!str_ends_with($migrationFile, '.php')) {
                        continue;
                    }

                    // Skip DDL aggregate files (e.g. CmsDdl.php, ForumDdl.php)
                    if (str_ends_with($migrationFile, 'Ddl.php')) {
                        continue;
                    }

                    /** @var MigrationInterface $migration */
                    $migration = require $migrationDir . '/' . $migrationFile;

                    if (!$migration instanceof MigrationInterface) {
                        continue;
                    }

                    try {
                        $migration->up($dbConnection);
                        $totalMigrations++;
                    } catch (Throwable $migrationError) {
                        error_log('[Admin Dev] Migration ' . $migrationFile . ' failed: ' . $migrationError->getMessage());
                    }
                }
            }
        }

        if ($totalMigrations > 0) {
            error_log('[Admin Dev] Auto-migrated database schema (' . $totalMigrations . ' migrations)');
        }

        $storageDir = $dir . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0o755, true);
        }
        @file_put_contents($markerFile, date('c'));
    }
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

echo $response->getBody();
