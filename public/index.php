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

require __DIR__ . '/../vendor/autoload.php';

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

$configManager = new ConfigManager(__DIR__ . '/../config');

/*
|--------------------------------------------------------------------------
| Extension Discovery
|--------------------------------------------------------------------------
|
| Scan the extensions/ directory for pulsar.json manifests. Each extension
| is validated, dependency-sorted, and registered into the kernel lifecycle.
| The CMS, forum, analytics, and any other extensions boot automatically.
|
*/

$extensions = ExtensionBootstrap::create();
$extensions->loadFromPaths([__DIR__ . '/../extensions']);

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
