<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Extensibility\ExtensionBootstrap;

use function array_keys;
use function basename;
use function glob;
use function in_array;
use function is_file;
use function sprintf;
use function strrpos;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Emits, once at boot, the configuration diagnostics that would otherwise stay
 * silent.
 *
 * Three things were invisible before: {@see ExtensionBootstrap} collects its
 * load warnings against the bootstrap's own logger — a NullLogger in the common
 * auto-discovery path — and nobody read {@see ExtensionBootstrap::getLoadWarnings()}
 * afterwards; nothing related a `config/<ext>.php` to whether that extension is
 * actually on; and nothing flagged a config file that no framework section or
 * extension reads at all. A stray config file could therefore sit ignored
 * indefinitely. This reporter runs with the real logger, after extensions
 * register, and closes all three gaps.
 */
#[Internal]
final class ConfigDiagnosticsReporter
{
    /**
     * Config basenames Pulsar ships with — framework sections plus the config
     * files of bundled extensions. A config file whose basename is neither in
     * here nor consumed by an installed extension is unread and reported.
     *
     * Kept complete by {@see \Pulsar\Tests\Unit\Core\Boot\ConfigDiagnosticsReporterTest}:
     * the drift test asserts every shipped config/*.php basename is listed, so a
     * new config file cannot silently escape (or falsely trip) the orphan check.
     *
     * @var list<string>
     */
    private const array KNOWN_CONFIG_SECTIONS = [
        'admin', 'anti-spam', 'api', 'app', 'broadcasting', 'business', 'cache', 'compliance',
        'data_protection', 'database', 'deploy', 'dev', 'documentation', 'domains', 'edge', 'event',
        'extensions', 'features', 'i18n', 'integrity', 'introspection', 'live', 'mail', 'marketplace',
        'notification', 'observability', 'openapi', 'opentelemetry', 'profiler', 'queue', 'repl',
        'resilience', 'routing', 'runtime', 'scheduler', 'security', 'storage', 'studio', 'supervisor',
        'supply-chain', 'tenancy', 'view',
    ];

    public static function report(
        ?ConfigManager $configManager,
        ?ExtensionBootstrap $bootstrap,
        ?LoggerInterface $logger,
    ): void {
        if ($logger === null) {
            return;
        }

        // Unrecognized config keys detected at load time (non-strict mode; strict
        // mode aborts the boot before we get here). Names the section and key so
        // a typo is no longer silently dropped to a default.
        foreach ($configManager?->unknownConfigKeyWarnings() ?? [] as $warning) {
            $logger->warning($warning);
        }

        if ($bootstrap === null) {
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

        // A config file whose extension is switched off — by the extensions.enabled
        // allowlist or because it is an off-by-default bundled product — is a
        // likely mistake: the operator wrote config that is never read.
        foreach ($bootstrap->disabledByConfig() as $extensionName) {
            $basename = self::configBasename($extensionName);
            $file = $configPath . DIRECTORY_SEPARATOR . $basename . '.php';

            if (is_file($file)) {
                $logger->warning(sprintf(
                    'Config file "config/%s.php" exists but extension "%s" is disabled, so the file is '
                    . 'never read. Enable it (extensions.enabled or extensions.enabled_products) or remove the config.',
                    $basename,
                    $extensionName,
                ));
            }
        }

        self::reportOrphanedConfigFiles($configPath, $bootstrap, $logger);
    }

    /**
     * Warn about config files that no framework section and no installed
     * extension consumes — a stale file, or the config for an extension that was
     * never installed. Extensions disabled by config are excluded here: they get
     * the more specific message above rather than a generic orphan warning.
     */
    private static function reportOrphanedConfigFiles(
        string $configPath,
        ExtensionBootstrap $bootstrap,
        LoggerInterface $logger,
    ): void {
        $recognized = self::KNOWN_CONFIG_SECTIONS;

        foreach (array_keys($bootstrap->getManifests()) as $extensionName) {
            $recognized[] = self::configBasename($extensionName);
        }

        foreach ($bootstrap->disabledByConfig() as $extensionName) {
            $recognized[] = self::configBasename($extensionName);
        }

        foreach (glob($configPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $basename = basename($file, '.php');

            if (!in_array($basename, $recognized, true)) {
                $logger->warning(sprintf(
                    'Config file "config/%s.php" is not read by any framework section or installed '
                    . 'extension — the file is stale, or the extension that consumes it is not installed.',
                    $basename,
                ));
            }
        }
    }

    /**
     * Config basenames Pulsar ships with (framework sections + bundled extensions).
     * Exposed for the drift test that keeps {@see KNOWN_CONFIG_SECTIONS} complete.
     *
     * @return list<string>
     */
    public static function knownConfigSections(): array
    {
        return self::KNOWN_CONFIG_SECTIONS;
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
