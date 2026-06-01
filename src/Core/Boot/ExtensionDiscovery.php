<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Extensibility\ExtensionBootstrap;

use function dirname;

/**
 * Auto-discovers extensions from the project's extensions/ directory.
 *
 * Used when no {@see ExtensionBootstrap} was provided (common in HTTP entry
 * points): scans `<projectRoot>/extensions` for pulsar.json manifests and
 * returns a bootstrap, or null when no extensions directory exists. The
 * project root is derived from the config path (parent of config/), falling
 * back to the current working directory. Extracted from {@see \Pulsar\Core\Kernel};
 * boot-time only.
 */
#[Internal]
final class ExtensionDiscovery
{
    public static function discover(?ConfigManager $configManager): ?ExtensionBootstrap
    {
        // Derive the project root from the config path (parent of config/).
        // Falls back to getcwd() when no config path is available.
        $configPath = $configManager?->configPath();
        $projectRoot = $configPath !== null ? dirname($configPath) : (getcwd() ?: null);

        if ($projectRoot === null) {
            return null;
        }

        $extensionsDir = $projectRoot . DIRECTORY_SEPARATOR . 'extensions';

        if (!is_dir($extensionsDir)) {
            return null;
        }

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->loadFromPaths([$extensionsDir]);

        return $bootstrap;
    }
}
