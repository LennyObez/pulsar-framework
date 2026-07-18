<?php

declare(strict_types=1);

/**
 * Pulsar Framework — Front Controller
 *
 * All HTTP requests are routed through this file by the web server.
 * The kernel boots the framework, discovers extensions (including the
 * CMS when enabled), and dispatches the request.
 *
 * Web server configuration:
 *   Apache  — the .htaccess file in public/ handles rewrites
 *   Nginx   — rewrite all non-file requests to /index.php
 *   Built-in — php -S localhost:8000 -t public
 */

/*
|--------------------------------------------------------------------------
| Project Root
|--------------------------------------------------------------------------
|
| Set PULSAR_BASE_PATH so that base_path(), storage_path(), and all other
| path helpers resolve against the project root, not the web server's CWD.
| This is critical when using `php -S localhost:8000 -t public` because
| the CWD is set to public/, not the project root.
|
*/

$basePath = dirname(__DIR__);

// Only when unset, so an FPM pool env / systemd Environment= / container env
// can override the project root without editing this file.
if (getenv('PULSAR_BASE_PATH') === false || getenv('PULSAR_BASE_PATH') === '') {
    putenv('PULSAR_BASE_PATH=' . $basePath);
}

require $basePath . '/vendor/autoload.php';

use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Load application configuration from the config/ directory. Environment
| variables from .env are expected to be loaded by the time this runs
| (via Dotenv in config/app.php or the web server environment).
|
*/

$configManager = new ConfigManager(
    configPath: $basePath . '/config',
    envFilePath: $basePath . '/.env',
);

/*
|--------------------------------------------------------------------------
| Extension Discovery
|--------------------------------------------------------------------------
|
| Discover extensions from the framework's bundled extensions directory
| and the project's own extensions/ directory (if it exists).
|
| If config/app.php defines extensions.enabled, only those extensions
| are loaded. Otherwise, all discovered extensions boot.
|
*/

$extensions = ExtensionBootstrap::create();

// Build extension paths: framework extensions + project-local extensions
$extensionPaths = [];
$frameworkExtensions = $basePath . '/vendor/pulsar/framework/extensions';
if (is_dir($frameworkExtensions)) {
    $extensionPaths[] = $frameworkExtensions;
}
$projectExtensions = $basePath . '/extensions';
if (is_dir($projectExtensions)) {
    $extensionPaths[] = $projectExtensions;
}
// Fallback: running from within the framework repo itself
if ($extensionPaths === [] && is_dir($basePath . '/extensions')) {
    $extensionPaths[] = $basePath . '/extensions';
}

// Read extensions.enabled filter from app config
$appConfigFile = $basePath . '/config/app.php';
if (is_file($appConfigFile)) {
    /** @var array<string, mixed>|mixed $appConfig */
    $appConfig = require $appConfigFile;
    if (is_array($appConfig)) {
        /** @var array<string, mixed>|mixed $extConfig */
        $extConfig = $appConfig['extensions'] ?? null;
        if (is_array($extConfig) && isset($extConfig['enabled']) && is_array($extConfig['enabled'])) {
            /** @var list<string> $enabled */
            $enabled = $extConfig['enabled'];
            $extensions->setEnabledFilter($enabled);
        }
    }
}

$extensions->loadFromPaths($extensionPaths);

/*
|--------------------------------------------------------------------------
| Kernel Boot & Request Handling
|--------------------------------------------------------------------------
|
| The kernel wires all core services (security, database, auth, mail,
| queues, etc.), runs the extension lifecycle, then dispatches the
| incoming HTTP request through the middleware pipeline to the matched
| route handler. The response is emitted back to the client.
|
*/

$kernel = new Kernel(
    extensionBootstrap: $extensions,
    configManager: $configManager,
);

$kernel->run();
