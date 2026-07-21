<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Extensibility\ExtensionBootstrap;

use function dirname;
use function is_array;
use function is_file;
use function is_string;

use const DIRECTORY_SEPARATOR;

/**
 * Auto-discovers extensions from the project's extensions/ directory.
 *
 * Used when no {@see ExtensionBootstrap} was provided (common in HTTP entry
 * points): scans `<projectRoot>/extensions` for pulsar.json manifests and
 * returns a bootstrap, or null when no extensions directory exists. The
 * project root is derived from the config path (parent of config/), falling
 * back to the current working directory. Extracted from {@see \Pulsar\Core\Kernel};
 * boot-time only.
 *
 * Honors `extensions.enabled` from config/app.php exactly as the scaffolded
 * entry point does: an operator who lists a subset there must get that subset
 * whether the kernel is handed an explicit bootstrap or falls back to this
 * auto-discovery path. Without this, disabling an extension by config was a
 * no-op under auto-discovery — every extension on disk booted regardless,
 * silently widening the attack surface the operator believed they had closed.
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

        $enabled = self::enabledFilter($configPath);
        if ($enabled !== null) {
            $bootstrap->setEnabledFilter($enabled);
        }

        $bootstrap->loadFromPaths([$extensionsDir]);

        return $bootstrap;
    }

    /**
     * Read `extensions.enabled` from config/app.php as a list of extension names.
     *
     * Returns null when the config directory, the app.php file, or the
     * `extensions.enabled` key is absent — meaning "load everything discovered",
     * the backward-compatible default. A present-but-non-string entry is dropped
     * rather than trusted, so a malformed config cannot smuggle a non-name into
     * the allow-list.
     *
     * @return list<string>|null
     */
    private static function enabledFilter(?string $configPath): ?array
    {
        if ($configPath === null) {
            return null;
        }

        $appConfigFile = $configPath . DIRECTORY_SEPARATOR . 'app.php';

        if (!is_file($appConfigFile)) {
            return null;
        }

        /** @var mixed $cfg */
        $cfg = require $appConfigFile;

        if (!is_array($cfg) || !isset($cfg['extensions']) || !is_array($cfg['extensions'])) {
            return null;
        }

        $enabled = $cfg['extensions']['enabled'] ?? null;

        if (!is_array($enabled)) {
            return null;
        }

        // Keep only the string entries; a malformed enabled list (non-strings)
        // is filtered rather than trusted. array_filter with is_string narrows
        // the value type, so no mixed value is ever bound.
        return array_values(array_filter($enabled, 'is_string'));
    }
}
