<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;

// Load only the health-status extension
$extensions = ExtensionBootstrap::create();
$extensions->loadFromPaths([__DIR__ . '/extensions/health-status']);

$configManager = new ConfigManager(
    configPath: __DIR__ . '/config',
    envFilePath: __DIR__ . '/.env',
);

$kernel = new Kernel(
    extensionBootstrap: $extensions,
    configManager: $configManager,
);

$kernel->run();
