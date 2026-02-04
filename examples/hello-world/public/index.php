<?php

declare(strict_types=1);

/**
 * Pulsar Hello World Example
 *
 * Entry point for the hello-world example application.
 *
 * Usage:
 *   php -S localhost:8080 -t examples/hello-world/public
 */

// Autoload from project root (three levels up from public/)
$projectRoot = dirname(__DIR__, 3);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Composer autoloader not found. Run 'composer install' from the project root.\n");
    exit(1);
}

require $autoloadPath;

// Also autoload the example app's classes
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

// Create kernel
$kernel = new Kernel();

// Load routes
$registerRoutes = require __DIR__ . '/../routes.php';
$registerRoutes($kernel->router());

// Handle request
$kernel->run();
