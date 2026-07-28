<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring\Internal;

use Psr\Log\LoggerInterface;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeyReporter;
use Pulsar\Container\ContainerInterface;

/**
 * Shared boot-time unknown-key reporting for wirings that load their own config.
 *
 * {@see \Pulsar\Config\ConfigManager} audits the sections it owns, but each wiring
 * that reads a `config/*.php` file for itself — edge, documentation, marketplace,
 * and the rest — is outside that sweep. This lets such a wiring surface a typo in
 * its file at boot with the same warning, without every wiring repeating the
 * "resolve the logger if one is bound yet" dance.
 *
 * The section label is passed explicitly rather than derived from the DTO class:
 * a class name lowercased loses the separators of `anti-spam`, `data_protection`
 * and `supply-chain`, so it would name a file that does not exist.
 */
trait ReportsConfigKeys
{
    /**
     * Log any unrecognized keys the config carries, if a logger is bound.
     *
     * Called right after the DTO is built and registered, before any early return
     * for a disabled feature — a typo must surface whether or not the feature ends
     * up active. A no-op when no logger is bound yet (the same graceful degradation
     * {@see \Pulsar\Core\Boot\ConfigDiagnosticsReporter} applies).
     *
     * @param non-empty-string $section The config file's basename, e.g. 'edge'.
     */
    private function reportUnknownConfigKeys(
        ContainerInterface $container,
        string $section,
        ReportsUnknownKeys $config,
    ): void {
        if (!$container->has(LoggerInterface::class)) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);

        UnknownKeyReporter::report($logger, $section, $config);
    }
}
