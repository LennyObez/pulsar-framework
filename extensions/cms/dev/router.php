<?php

declare(strict_types=1);

/**
 * Router script for the CMS development server (php -S).
 *
 * Bootstraps the full framework kernel and dispatches requests through it.
 * CMS routes (public + admin), database, auth, and all extensions are available.
 *
 * Usage: php -S host:port -t . extensions/cms/dev/router.php
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

use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
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
use Override;
use Pulsar\View\ViewConfig;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

$mimeTypes = [
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'map' => 'application/json; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'ico' => 'image/x-icon',
];

// Redirect /admin to /admin/cms (CMS dashboard is the landing page)
if ($path === '/admin' || $path === '/admin/') {
    header('Location: /admin/cms', true, 302);

    return;
}

// Serve static assets from the admin extension frontend
$adminAssetPrefix = '/admin/assets/';
if (str_starts_with($path, $adminAssetPrefix)) {
    $assetPath = substr($path, strlen($adminAssetPrefix));

    $candidates = [
        $dir . '/extensions/admin/frontend/styles/' . $assetPath,
        $dir . '/extensions/admin/frontend/dist/' . $assetPath,
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

// Serve static assets from the CMS extension frontend
$cmsAssetPrefix = '/admin/cms/assets/';
if (str_starts_with($path, $cmsAssetPrefix)) {
    $assetPath = substr($path, strlen($cmsAssetPrefix));

    $candidates = [
        $dir . '/extensions/cms/frontend/styles/' . $assetPath,
        $dir . '/extensions/cms/frontend/dist/' . $assetPath,
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

// Ensure a PULSAR_MASTER_KEY is available for dev (required by commerce service chains).
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
// This is injected before boot() so CmsExtension::preBoot() sees it
// and skips loading from config/cms.php (which has commerce disabled by default).
$kernel->container()->instance(CmsConfig::class, new CmsConfig(
    editorialWorkflow: true,
    eventSourcing: true,
    atomicSnapshots: true,
    commerce: new CommerceConfig(),
));

// Pre-register ViewConfig for dev server (template paths + cache)
$kernel->container()->instance(ViewConfig::class, new ViewConfig(
    templatePaths: [
        $dir . '/resources/views',
        $dir . '/extensions/cms/resources/views',
    ],
    cachePath: $dir . '/storage/cache/views',
    phpDirectiveAllowed: true,
));

// Pre-register a permissive GateInterface for dev — grants all permissions
$kernel->container()->instance(GateInterface::class, new class implements GateInterface {
    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return $identity->isAuthenticated();
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }
});

try {
    $kernel->boot();
} catch (Throwable $e) {
    error_log('[CMS Dev] Kernel boot failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Kernel boot failed: ' . $e->getMessage();

    return;
}

// Auto-migrate CMS tables if a database is configured but schema is missing.
// This ensures zero-configuration dev experience: `cms:serve` works immediately
// without requiring a manual `migrate:run` step.
$container = $kernel->container();
if ($container->has(ConnectionInterface::class)) {
    /** @var ConnectionInterface $dbConnection */
    $dbConnection = $container->get(ConnectionInterface::class);

    $markerFile = $dir . '/storage/.cms-schema-ready';
    if (!file_exists($markerFile)) {
        // Create auth_users table (framework auth table required by CMS user management)
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
            error_log('[CMS Dev] auth_users table creation failed: ' . $authMigrationError->getMessage());
        }

        $migrationDir = dirname(__DIR__) . '/src/Migration';
        $migrationFiles = is_dir($migrationDir) ? scandir($migrationDir) : [];

        if ($migrationFiles !== false) {
            sort($migrationFiles);

            foreach ($migrationFiles as $migrationFile) {
                if (!str_ends_with($migrationFile, '.php') || $migrationFile === 'CmsDdl.php') {
                    continue;
                }

                /** @var MigrationInterface $migration */
                $migration = require $migrationDir . '/' . $migrationFile;

                if (!$migration instanceof MigrationInterface) {
                    continue;
                }

                try {
                    $migration->up($dbConnection);
                } catch (Throwable $migrationError) {
                    error_log('[CMS Dev] Migration ' . $migrationFile . ' failed: ' . $migrationError->getMessage());
                }
            }

            $migrationCount = count(array_filter(
                $migrationFiles,
                static fn(string $f) => str_ends_with($f, '.php') && $f !== 'CmsDdl.php',
            ));
            error_log('[CMS Dev] Auto-migrated CMS database schema (' . $migrationCount . ' migrations)');
        }

        // Verify critical tables exist before writing marker
        $criticalTables = ['cms_contents', 'cms_redirects', 'cms_editorial_reviews',
                           'cms_site_settings', 'cms_menus', 'cms_media_assets'];
        $allReady = true;

        foreach ($criticalTables as $table) {
            try {
                $dbConnection->query("SELECT 1 FROM $table LIMIT 1");
            } catch (Throwable) {
                $allReady = false;
                error_log("[CMS Dev] Table $table still missing after migration");
            }
        }

        if ($allReady) {
            $storageDir = $dir . '/storage';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0o755, true);
            }
            @file_put_contents($markerFile, date('c'));
        }
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

// Inject a dev admin identity so controllers that call requireIdentity() succeed.
// In production, real auth middleware resolves the identity from session/token.
$devIdentity = new Identity(
    id: 'dev-admin',
    displayName: 'Dev Administrator',
    roles: ['admin', 'editor', 'moderator'],
    twoFactorStatus: TwoFactorStatus::Verified,
);
$request = $request->withAttribute('identity', $devIdentity);
$request = $request->withAttribute('_identity', $devIdentity);
$request = $request->withAttribute('step_up_verified', true);

try {
    $response = $kernel->handle($request);
} catch (Throwable $e) {
    error_log(sprintf('[%d]: %s %s - 500 Internal Server Error', $_SERVER['REMOTE_PORT'] ?? 0, $method, $requestUri));
    http_response_code(500);
    echo 'Internal Server Error: ' . $e->getMessage();

    return;
}

// Log request like PHP's built-in server
error_log(sprintf('[%d]: %s %s - %d', $_SERVER['REMOTE_PORT'] ?? 0, $method, $requestUri, $response->getStatusCode()));

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
