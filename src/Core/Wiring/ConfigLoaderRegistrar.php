<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;

/**
 * Registers every wiring-owned config loader with {@see ConfigManager} before
 * `load()` runs, so each owned DTO is built into the ConfigRepository — the
 * single source of truth — during config loading.
 *
 * One implementation, shared by the composition root ({@see \Pulsar\Core\Kernel})
 * and the config-loading contract gate, so the two can never drift: the gate
 * verifies exactly the registration the real boot performs.
 */
#[Internal]
final class ConfigLoaderRegistrar
{
    /**
     * @param iterable<object> $wirings Boot wiring list; non-loader wirings are ignored.
     */
    public static function register(ConfigManager $configManager, iterable $wirings): void
    {
        foreach ($wirings as $wiring) {
            if (!$wiring instanceof ProvidesConfigLoaders) {
                continue;
            }

            foreach ($wiring->configLoaders() as $basename => $loader) {
                $configManager->registerLoader($basename, $loader);
            }
        }
    }
}
