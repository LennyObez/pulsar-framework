<?php

declare(strict_types=1);

/**
 * Pulsar Basic CRUD Example
 *
 * Demonstrates a simple CRUD API for articles.
 *
 * Usage:
 *   php -S localhost:8080 -t examples/basic-crud/public
 *
 * Endpoints:
 *   GET    /articles          - List all articles
 *   GET    /articles/{id}     - Show an article
 *   POST   /articles          - Create an article
 *   PUT    /articles/{id}     - Update an article
 *   DELETE /articles/{id}     - Delete an article
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
    echo json_encode(['error' => 'Internal Server Error']);
    error_log((string) $e);
}
