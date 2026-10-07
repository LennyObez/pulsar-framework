<?php

declare(strict_types=1);

/**
 * Router script for the PHP built-in development server.
 *
 * Usage: php -S localhost:8000 -t public public/router.php
 *
 * The PHP built-in server returns 404 for URLs with extensions (.css, .woff2, etc.)
 * when no matching file exists in the document root. This router ensures all requests
 * are passed to index.php, allowing the framework's AssetWiring to serve framework
 * assets from resources/ui/ and extension assets from their resources/ directories.
 *
 * Static files that DO exist in public/ (favicon.ico, images, etc.) are served directly.
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

// Only serve static files that exist within the document root.
// Reject traversal attempts and non-file paths.
if (
    $path !== false
    && $path !== '/'
    && !str_contains($path, '..')
    && !str_contains($path, "\0")
) {
    $candidate = __DIR__ . $path;
    $realBase = realpath(__DIR__);
    $realCandidate = realpath($candidate);

    // Serve only if the resolved path is within public/ and is a regular file
    if (
        $realBase !== false
        && $realCandidate !== false
        && str_starts_with($realCandidate, $realBase . DIRECTORY_SEPARATOR)
        && is_file($realCandidate)
    ) {
        return false; // Let the built-in server handle it
    }
}

// Everything else goes through the framework
require __DIR__ . '/index.php';
