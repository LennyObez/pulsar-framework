<?php

declare(strict_types=1);

use function dirname;
use function file_exists;

use const PHP_VERSION;
use const PHP_VERSION_ID;

use RuntimeException;

use function sprintf;

/**
 * Pulsar Framework Bootstrap
 *
 * This file is the single entry point for kernel initialization.
 * It loads Composer autoloading and prepares the application environment.
 *
 * @package Pulsar
 */

// Ensure we're running PHP 8.5+
if (PHP_VERSION_ID < 80500) {
    throw new RuntimeException(
        sprintf('Pulsar requires PHP 8.5 or higher. Current version: %s', PHP_VERSION),
    );
}

// Load Composer autoloader
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    throw new RuntimeException(
        'Composer autoload not found. Run "composer install" first.',
    );
}

require_once $autoloadPath;
