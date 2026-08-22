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

/*
|--------------------------------------------------------------------------
| Error Display
|--------------------------------------------------------------------------
|
| Set before anything else can fail. php.ini decides display_errors by
| default, and a distribution default of On turns any uncaught error into a
| page containing the exception class, its message, absolute source paths and
| the full call stack — appended to whatever had already been written, so the
| response can even carry HTTP 200. Only APP_DEBUG in the real process
| environment opts back in; the dotenv file has not been read at this point
| and must not be trusted to decide this.
|
*/

$pulsarDebug = in_array(strtolower((string) getenv('APP_DEBUG')), ['1', 'true', 'on', 'yes'], true);

ini_set('display_errors', $pulsarDebug ? '1' : '0');
ini_set('log_errors', '1');

require $basePath . '/vendor/autoload.php';

use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\ResponseStatus;

/*
|--------------------------------------------------------------------------
| Last-Resort Error Handlers
|--------------------------------------------------------------------------
|
| The kernel guards its own request cycle, but nothing guards the frame
| around it: config loading, extension discovery, an out-of-memory kill, a
| parse error in an extension file. Those bypass every try/catch in the
| framework and land on the SAPI's own error output.
|
| These two handlers are the floor. They discard whatever partial output
| exists and emit the same generic ProductionRenderer page the kernel would
| have produced, with its security headers. When the response is already
| committed (headers_sent) nothing can be corrected, so they stand down and
| leave the record to the error log.
|
*/

$pulsarLastResort = static function (): void {
    if (headers_sent()) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    try {
        new ResponseEmitter()->emit(
            new ProductionRenderer()->response(ResponseStatus::InternalServerError),
        );
    } catch (Throwable) {
        // Emitting failed too — a bare status line still beats a blank 200.
        http_response_code(ResponseStatus::InternalServerError->value);
    }
};

set_exception_handler(static function (Throwable $e) use ($pulsarLastResort): void {
    error_log(sprintf('[Pulsar] Uncaught %s: %s', $e::class, (string) $e));
    $pulsarLastResort();
});

register_shutdown_function(static function () use ($pulsarLastResort): void {
    $last = error_get_last();

    // Warnings and notices left the script alive and are not ours to answer.
    // Only the classes that end execution get the last-resort page.
    if ($last === null || ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) === 0) {
        return;
    }

    $pulsarLastResort();
});

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

// Read the extension enable posture from app config: the optional exclusive
// allowlist (extensions.enabled) and the additive product opt-in
// (extensions.enabled_products). With neither set, bundled products stay off by
// default (manifest kind: product) while infrastructure and the app's own
// extensions load.
$appConfigFile = $basePath . '/config/app.php';
if (is_file($appConfigFile)) {
    /** @var array<string, mixed>|mixed $appConfig */
    $appConfig = require $appConfigFile;
    if (is_array($appConfig)) {
        /** @var array<string, mixed>|mixed $extConfig */
        $extConfig = $appConfig['extensions'] ?? null;
        if (is_array($extConfig)) {
            if (isset($extConfig['enabled']) && is_array($extConfig['enabled'])) {
                /** @var list<string> $enabled */
                $enabled = $extConfig['enabled'];
                $extensions->setEnabledFilter($enabled);
            }
            if (isset($extConfig['enabled_products']) && is_array($extConfig['enabled_products'])) {
                /** @var list<string> $enabledProducts */
                $enabledProducts = $extConfig['enabled_products'];
                $extensions->setEnabledProducts($enabledProducts);
            }
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
