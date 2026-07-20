<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Extensibility\ExtensionBootstrap;

use function is_file;
use function sprintf;
use function strrpos;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Emits, once at boot, the configuration diagnostics that would otherwise stay
 * silent.
 *
 * Two things were invisible before: {@see ExtensionBootstrap} collects its load
 * warnings against the bootstrap's own logger — a NullLogger in the common
 * auto-discovery path — and nobody read {@see ExtensionBootstrap::getLoadWarnings()}
 * afterwards; and nothing related a `config/<ext>.php` to whether that extension
 * is actually on. The upshot was that a stray config file for a disabled
 * extension could sit ignored indefinitely. This reporter runs with the real
 * logger, after extensions register, and closes both gaps.
 */
#[Internal]
final class ConfigDiagnosticsReporter
{
    public static function report(
        ?ConfigManager $configManager,
        ?ExtensionBootstrap $bootstrap,
        ?LoggerInterface $logger,
    ): void {
        if ($logger === null || $bootstrap === null) {
            return;
        }

        // Surface the extension load warnings (incompatible versions, missing
        // classes, instantiation failures, and the disabled-by-config summary)
        // that were recorded against the bootstrap's own logger.
        foreach ($bootstrap->getLoadWarnings() as $warning) {
            $logger->warning($warning);
        }

        $configPath = $configManager?->configPath();

        if ($configPath === null) {
            return;
        }

        // A config file whose extension was switched off via extensions.enabled
        // is a likely mistake: the operator wrote config that is never read.
        foreach ($bootstrap->disabledByConfig() as $extensionName) {
            $basename = self::configBasename($extensionName);
            $file = $configPath . DIRECTORY_SEPARATOR . $basename . '.php';

            if (is_file($file)) {
                $logger->warning(sprintf(
                    'Config file "config/%s.php" exists but extension "%s" is disabled via '
                    . 'extensions.enabled, so the file is never read. Enable the extension or remove the config.',
                    $basename,
                    $extensionName,
                ));
            }
        }
    }

    /**
     * Map an extension name to its conventional config basename: the segment
     * after the last slash, so "pulsar/cms" resolves to "cms" (config/cms.php).
     */
    private static function configBasename(string $extensionName): string
    {
        $slash = strrpos($extensionName, '/');

        return $slash === false ? $extensionName : substr($extensionName, $slash + 1);
    }
}
