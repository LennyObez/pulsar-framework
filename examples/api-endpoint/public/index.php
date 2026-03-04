<?php

declare(strict_types=1);

/**
 * Pulsar API Endpoint Example
 *
 * Demonstrates a REST API with validation and authentication patterns.
 *
 * Usage:
 *   php -S localhost:8080 -t examples/api-endpoint/public
 *
 * Endpoints:
 *   GET  /api/v1/health         - Health check (public)
 *   GET  /api/v1/users          - List users (supports ?role= filter)
 *   GET  /api/v1/users/{id}     - Show user
 *   POST /api/v1/users          - Create user (validated)
 *
 * Example:
 *   curl -X POST http://localhost:8080/api/v1/users \
 *     -H "Content-Type: application/json" \
 *     -d '{"name":"Charlie","email":"charlie@example.com","role":"viewer"}'
 */

$projectRoot = dirname(__DIR__, 3);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Composer autoloader not found. Run 'composer install' from the project root.\n");
    exit(1);
}

require $autoloadPath;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use Pulsar\Core\Kernel;

$kernel = new Kernel();

$registerRoutes = require __DIR__ . '/../routes.php';
$registerRoutes($kernel->router());

try {
    $kernel->run();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal Server Error']);
    error_log((string) $e);
}
