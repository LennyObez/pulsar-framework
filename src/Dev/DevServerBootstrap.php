<?php

declare(strict_types=1);

namespace Pulsar\Dev;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\I18nConfig;
use Pulsar\Core\Kernel;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\I18n\Catalog\ChainCatalog;
use Pulsar\I18n\Catalog\PhpCatalog;
use Pulsar\I18n\Format\FallbackMessageFormatter;
use Pulsar\I18n\Format\IcuMessageFormatter;
use Pulsar\I18n\Translator;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\View\ViewConfig;
use Throwable;

use function date;
use function error_log;
use function extension_loaded;
use function file_exists;
use function getenv;
use function header;
use function http_response_code;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function mkdir;
use function parse_url;
use function pathinfo;
use function putenv;
use function readfile;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const DIRECTORY_SEPARATOR;
use const PHP_URL_PATH;

/**
 * Shared bootstrap for all extension dev servers (php -S).
 *
 * Eliminates boilerplate duplication across dev routers by providing:
 * - Static asset serving (UI design system, extension CSS/JS)
 * - MIME type resolution with a single filesystem call
 * - Translation system bootstrap (makes __(), @t() work in templates)
 * - Kernel boot with dev identity injection
 * - Request dispatch and response emission
 *
 * Each dev router becomes ~20 lines: require autoload, configure, run.
 */
#[Internal(reason: 'Dev server implementation detail; not part of public API')]
final class DevServerBootstrap
{
    /** MIME types for static file serving, keyed by extension. */
    private const array MIME_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    /** Cached realpath for the UI resources directory. */
    private static ?string $uiDir = null;

    /** Cached kernel instance (persists across requests in php -S). */
    private static ?Kernel $cachedKernel = null;

    /** Extension name of the cached kernel (invalidate on config change). */
    private static ?string $cachedKernelExtension = null;

    /**
     * Run the dev server bootstrap for a kernel-dispatched extension.
     *
     * Handles static assets, boots the kernel, dispatches the request,
     * and emits the response. This is the entry point for admin, cms,
     * forum, and similar kernel-based dev routers.
     *
     * @param string $projectRoot Absolute path to the project root
     * @param DevServerConfig $config Extension-specific configuration
     */
    public static function run(string $projectRoot, DevServerConfig $config): void
    {
        $rawRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        $requestUri = is_string($rawRequestUri) ? $rawRequestUri : '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        // 1. Serve static assets (fast path; no kernel boot needed)
        if (self::serveStaticAsset($projectRoot, $path, $config)) {
            return;
        }

        // 2. Short-circuit browser probes
        if (str_starts_with($path, '/.well-known/') || $path === '/favicon.ico') {
            http_response_code(404);

            return;
        }

        // 3. Handle redirects
        foreach ($config->redirects as $from => $to) {
            if ($path === $from || $path === $from . '/') {
                header('Location: ' . $to, true, 302);

                return;
            }
        }

        // 4. Boot the framework kernel
        $kernel = self::bootKernel($projectRoot, $config);

        if ($kernel === null) {
            return; // Error already emitted
        }

        // 5. Bootstrap translation system for dev
        self::bootstrapTranslations($projectRoot, $kernel);

        // 6. Auto-migrate if configured
        if ($config->autoMigrate) {
            self::autoMigrate($projectRoot, $kernel, $config);
        }

        // 7. Build and dispatch request
        $request = self::buildRequest($config);

        try {
            $response = $kernel->handle($request);
        } catch (Throwable $e) {
            $rawErrorPort = $_SERVER['REMOTE_PORT'] ?? null;
            $errorPort = is_numeric($rawErrorPort) ? (int) $rawErrorPort : 0;
            $rawErrorMethod = $_SERVER['REQUEST_METHOD'] ?? null;
            $errorMethod = is_string($rawErrorMethod) ? $rawErrorMethod : 'GET';
            error_log(sprintf(
                '[%d]: %s %s - 500 Internal Server Error: %s',
                $errorPort,
                $errorMethod,
                $requestUri,
                $e->getMessage(),
            ));
            http_response_code(500);
            echo 'Internal Server Error: ' . $e->getMessage();

            return;
        }

        // 8. Log and emit response
        $rawPort = $_SERVER['REMOTE_PORT'] ?? null;
        $port = is_numeric($rawPort) ? (int) $rawPort : 0;
        $rawMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $method = is_string($rawMethod) ? $rawMethod : 'GET';
        error_log(sprintf('[%d]: %s %s - %d', $port, $method, $requestUri, $response->getStatusCode()));

        self::emitResponse($response);
    }

    /**
     * Serve a static asset if the path matches.
     *
     * Checks UI assets (/ui/), then extension-specific asset prefixes.
     * Uses cached realpath for the UI directory to avoid redundant stat calls.
     *
     * @param string $projectRoot Absolute path to project root
     * @param string $path Request path (e.g., /ui/css/pulsar-ui.css)
     * @param DevServerConfig $config Extension configuration
     *
     * @return bool True if asset was served, false to continue to kernel
     */
    public static function serveStaticAsset(string $projectRoot, string $path, DevServerConfig $config): bool
    {
        // Serve Pulsar UI design system assets (/ui/)
        self::$uiDir ??= realpath($projectRoot . '/resources/ui') ?: null;

        if (self::$uiDir !== null && str_starts_with($path, '/ui/')) {
            $file = self::$uiDir . str_replace('/', DIRECTORY_SEPARATOR, substr($path, 3));

            if (is_file($file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                header('Content-Type: ' . (self::MIME_TYPES[$ext] ?? 'application/octet-stream'));
                header('Cache-Control: public, max-age=86400');
                readfile($file);

                return true;
            }
        }

        // Serve extension-specific assets
        foreach ($config->assetPrefixes as $prefix => $directories) {
            if (!str_starts_with($path, $prefix)) {
                continue;
            }

            $assetPath = substr($path, strlen($prefix));

            foreach ($directories as $dir) {
                $fullDir = $projectRoot . '/' . $dir;
                $filePath = $fullDir . '/' . $assetPath;
                $realDir = realpath($fullDir);
                $realFile = realpath($filePath);

                if ($realDir !== false && $realFile !== false && is_file($realFile) && str_starts_with($realFile, $realDir)) {
                    $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
                    header('Content-Type: ' . (self::MIME_TYPES[$ext] ?? 'application/octet-stream'));
                    header('Cache-Control: no-cache');
                    readfile($realFile);

                    return true;
                }
            }

            // Prefix matched but no file found
            http_response_code(404);
            echo 'Asset not found';

            return true;
        }

        return false;
    }

    /**
     * Boot the Pulsar kernel with common dev defaults.
     *
     * Loads extensions, config, master key, FrameworkCache, and any
     * container pre-registrations specified in the config.
     *
     * The booted kernel is cached in a static variable so it persists
     * across requests when running under PHP's built-in dev server.
     * This avoids re-bootstrapping extensions, config, and wiring on
     * every request: typically reducing response times from ~2s to ~100ms.
     */
    public static function bootKernel(string $projectRoot, DevServerConfig $config): ?Kernel
    {
        // Return cached kernel if available (persists across php -S requests)
        if (self::$cachedKernel !== null && self::$cachedKernelExtension === $config->extensionName) {
            return self::$cachedKernel;
        }
        // Load extensions
        $extensionBootstrap = null;
        $extensionDir = $projectRoot . '/extensions';

        if (is_dir($extensionDir)) {
            $extensionBootstrap = ExtensionBootstrap::create();

            try {
                $extensionBootstrap->loadFromPaths([$extensionDir]);
            } catch (Throwable $e) {
                error_log('[' . $config->extensionName . ' Dev] Extension loading warning: ' . $e->getMessage());
            }
        }

        // Load config
        $configPath = $projectRoot . '/config';
        $envFile = $projectRoot . '/.env';
        $configManager = null;

        if (is_dir($configPath)) {
            // Auto-generate .env with secure defaults if it does not exist
            if (!is_file($envFile)) {
                $generatedKey = bin2hex(random_bytes(32));
                $generatedAppKey = 'base64:' . base64_encode(random_bytes(32));
                $appName = basename($projectRoot);
                $envContent = "APP_NAME={$appName}\nAPP_ENV=local\nAPP_DEBUG=true\nAPP_URL=http://localhost:8000\n\nAPP_KEY={$generatedAppKey}\nPULSAR_MASTER_KEY={$generatedKey}\n\nDB_CONNECTION=sqlite\nDB_DATABASE=database/pulsar.sqlite\n";
                file_put_contents($envFile, $envContent);
                error_log('[DevServer] Generated .env with secure defaults (SQLite, auto-generated keys).');

                $dbDir = $projectRoot . '/database';
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0o755, true);
                }
            }

            $configManager = new ConfigManager(
                configPath: $configPath,
                envFilePath: $envFile,
            );
        }

        $kernel = new Kernel(
            extensionBootstrap: $extensionBootstrap,
            configManager: $configManager,
        );

        // Ensure master key is in process environment (for early FrameworkCache)
        if (getenv('PULSAR_MASTER_KEY') === false || getenv('PULSAR_MASTER_KEY') === '') {
            putenv('PULSAR_MASTER_KEY=' . bin2hex(random_bytes(32)));
        }

        // Pre-boot FrameworkCache
        $masterKeyHex = getenv('PULSAR_MASTER_KEY');

        if ($masterKeyHex !== false && $masterKeyHex !== '' && $configManager !== null) {
            try {
                $earlyMasterKey = MasterKey::fromHex($masterKeyHex);
                $encrypt = getenv('CACHE_ENCRYPT') === 'true' || getenv('CACHE_ENCRYPT') === '1';
                $earlyCache = new FrameworkCache($projectRoot, $earlyMasterKey, new HmacService(), $encrypt);
                $kernel->container()->instance(FrameworkCache::class, $earlyCache);
            } catch (Throwable) {
                // Invalid key or sodium failure: skip pre-boot cache
            }
        }

        // Apply container pre-registrations from config
        foreach ($config->containerBindings as $abstract => $instance) {
            $kernel->container()->instance($abstract, $instance);
        }

        // Register ViewConfig if template paths are specified
        if ($config->templatePaths !== []) {
            $kernel->container()->instance(ViewConfig::class, new ViewConfig(
                templatePaths: $config->templatePaths,
                cachePath: $projectRoot . '/var/cache/views',
                phpDirectiveAllowed: true,
            ));
        }

        // Register permissive GateInterface for dev
        if ($config->injectDevIdentity) {
            $kernel->container()->instance(GateInterface::class, new DevPermissiveGate());
        }

        try {
            $kernel->boot();
        } catch (Throwable $e) {
            error_log('[' . $config->extensionName . ' Dev] Kernel boot failed: ' . $e->getMessage());
            http_response_code(500);
            echo 'Kernel boot failed: ' . $e->getMessage();

            return null;
        }

        // Cache the kernel for subsequent requests in php -S
        self::$cachedKernel = $kernel;
        self::$cachedKernelExtension = $config->extensionName;

        return $kernel;
    }

    /**
     * Bootstrap the translation system for dev servers.
     *
     * This makes __() and @t() work in templates by setting up the
     * Translator global instance with catalogs from the framework
     * and all extensions that provide translation files.
     */
    public static function bootstrapTranslations(string $projectRoot, Kernel $kernel): void
    {
        // Skip if the kernel already booted i18n (I18nConfig was present)
        if ($kernel->container()->has(TranslatorInterface::class)) {
            return;
        }

        // Collect all translation catalog paths
        $catalogPaths = [];

        // Framework core translations
        $coreLangPath = $projectRoot . '/resources/lang';

        if (is_dir($coreLangPath)) {
            $catalogPaths[] = $coreLangPath;
        }

        // Extension translations
        $extensionLangDirs = glob($projectRoot . '/extensions/*/resources/lang');

        if ($extensionLangDirs !== false) {
            foreach ($extensionLangDirs as $langDir) {
                if (is_dir($langDir)) {
                    $catalogPaths[] = $langDir;
                }
            }
        }

        if ($catalogPaths === []) {
            return;
        }

        // Build a chain catalog from all discovered paths
        $catalogs = [];

        foreach ($catalogPaths as $path) {
            $catalogs[] = new PhpCatalog($path);
        }

        $chainCatalog = new ChainCatalog(...$catalogs);

        // Build a minimal I18nConfig for dev
        $i18nConfig = new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            fallbackLocales: ['en'],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        );

        // Build message formatter
        $formatter = extension_loaded('intl')
            ? new IcuMessageFormatter()
            : new FallbackMessageFormatter();

        // Create and register the translator
        $translator = new Translator($chainCatalog, $i18nConfig, $formatter);
        Translator::setGlobalInstance($translator);
        $kernel->container()->instance(TranslatorInterface::class, $translator);
        $kernel->container()->instance(Translator::class, $translator);
    }

    /**
     * Build a ServerRequest from PHP globals with optional dev identity.
     */
    public static function buildRequest(DevServerConfig $config): ServerRequest
    {
        $request = ServerRequest::fromGlobals();

        if ($config->injectDevIdentity) {
            $devIdentity = new Identity(
                id: 'dev-admin',
                displayName: 'Dev Administrator',
                roles: $config->devIdentityRoles,
                twoFactorStatus: TwoFactorStatus::Verified,
            );
            $request = $request->withAttribute('identity', $devIdentity);
            $request = $request->withAttribute('_identity', $devIdentity);
            $request = $request->withAttribute('step_up_verified', true);
        }

        return $request;
    }

    /**
     * Emit an HTTP response to the client.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     */
    public static function emitResponse(object $response): void
    {
        /** @var \Pulsar\Http\Message\Response $response */
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            $first = true;

            foreach ($values as $value) {
                header($name . ': ' . $value, $first);
                $first = false;
            }
        }

        echo $response->getBody();
    }

    /**
     * Auto-migrate database tables for dev convenience.
     *
     * Creates the auth_users table and runs all migration files from
     * the configured migration directories. Uses a marker file to
     * avoid re-running on every request.
     */
    private static function autoMigrate(string $projectRoot, Kernel $kernel, DevServerConfig $config): void
    {
        $container = $kernel->container();

        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $db */
        $db = $container->get(ConnectionInterface::class);

        $markerFile = $projectRoot . '/var/cache/.' . $config->extensionName . '-schema-ready';

        if (file_exists($markerFile)) {
            return;
        }

        // Create auth_users table
        try {
            $db->execute(<<<'SQL'
                CREATE TABLE IF NOT EXISTS auth_users (
                    id VARCHAR(36) NOT NULL PRIMARY KEY,
                    tenant_id VARCHAR(36) DEFAULT NULL,
                    display_name VARCHAR(200) NOT NULL DEFAULT '',
                    email VARCHAR(320) DEFAULT NULL,
                    password_hash VARCHAR(255) DEFAULT NULL,
                    roles TEXT NOT NULL DEFAULT '[]',
                    two_factor_status VARCHAR(20) NOT NULL DEFAULT 'disabled',
                    two_factor_secret VARCHAR(255) DEFAULT NULL,
                    last_active_at TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    is_locked INTEGER NOT NULL DEFAULT 0
                )
                SQL);
        } catch (Throwable $e) {
            error_log('[' . $config->extensionName . ' Dev] auth_users creation failed: ' . $e->getMessage());
        }

        // Run additional setup SQL (e.g., password_resets, sessions tables)
        foreach ($config->setupSql as $sql) {
            try {
                $db->execute($sql);
            } catch (Throwable $e) {
                error_log('[' . $config->extensionName . ' Dev] Setup SQL failed: ' . $e->getMessage());
            }
        }

        // Run migration files
        $totalMigrations = 0;

        // Resolve migration dirs, expanding glob patterns
        $resolvedDirs = [];

        foreach ($config->migrationDirs as $migrationDir) {
            $fullPattern = str_starts_with($migrationDir, '/') || (strlen($migrationDir) > 1 && $migrationDir[1] === ':')
                ? $migrationDir
                : $projectRoot . '/' . $migrationDir;

            if (str_contains($fullPattern, '*')) {
                $expanded = glob($fullPattern);

                if ($expanded !== false) {
                    foreach ($expanded as $expandedDir) {
                        if (is_dir($expandedDir)) {
                            $resolvedDirs[] = $expandedDir;
                        }
                    }
                }
            } else {
                $resolvedDirs[] = $fullPattern;
            }
        }

        foreach ($resolvedDirs as $fullDir) {
            if (!is_dir($fullDir)) {
                continue;
            }

            $files = scandir($fullDir);

            if ($files === false) {
                continue;
            }

            sort($files);

            foreach ($files as $file) {
                if (!str_ends_with($file, '.php') || str_ends_with($file, 'Ddl.php')) {
                    continue;
                }

                $migration = require $fullDir . '/' . $file;

                if (!$migration instanceof MigrationInterface) {
                    continue;
                }

                try {
                    $migration->up($db);
                    $totalMigrations++;
                } catch (Throwable $e) {
                    error_log('[' . $config->extensionName . ' Dev] Migration ' . $file . ' failed: ' . $e->getMessage());
                }
            }
        }

        if ($totalMigrations > 0) {
            error_log('[' . $config->extensionName . ' Dev] Auto-migrated database schema (' . $totalMigrations . ' migrations)');
        }

        // Run post-migration seeders
        foreach ($config->seeders as $seeder) {
            try {
                $seeder($db);
            } catch (Throwable $e) {
                error_log('[' . $config->extensionName . ' Dev] Seeder failed: ' . $e->getMessage());
            }
        }

        // Verify critical tables
        $allReady = true;

        foreach ($config->criticalTables as $table) {
            try {
                $db->query("SELECT 1 FROM $table LIMIT 1");
            } catch (Throwable) {
                $allReady = false;
                error_log('[' . $config->extensionName . ' Dev] Table ' . $table . ' still missing after migration');
            }
        }

        if ($allReady) {
            $storageDir = $projectRoot . '/storage';

            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0o755, true);
            }

            @file_put_contents($markerFile, date('c'));
        }
    }

    /**
     * Get the MIME type map for testing.
     *
     * @return array<string, string>
     */
    public static function getMimeTypes(): array
    {
        return self::MIME_TYPES;
    }

    /**
     * Reset cached state between tests.
     */
    public static function resetCache(): void
    {
        self::$uiDir = null;
        self::$cachedKernel = null;
        self::$cachedKernelExtension = null;
    }
}
