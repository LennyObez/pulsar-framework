<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionConfigRegistry;

use function basename;
use function glob;
use function is_file;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * Publishes the configuration each extension ships as {@see ExtensionConfigRegistry}.
 *
 * Sixteen bundled extensions ship a `config/<name>.php` that nothing ever read.
 * The files were not even dead weight — the extension graph compiler hashes them
 * for cache invalidation, so the convention was known and enforced everywhere
 * except at the point where it mattered.
 *
 * Resolution order for a section:
 *   1. `{app config dir}/<name>.php` when the host ships one — the host has the
 *      final word on how an extension it installed is configured.
 *   2. `{extension path}/config/<name>.php` — the extension's own default.
 *
 * The host file replaces the extension file rather than merging into it. A deep
 * merge would leave the effective value of any key impossible to read off either
 * file, which is precisely the kind of implicit behaviour Pulsar avoids.
 *
 * Section names normalise `-` to `_`, so `ai-governance.php` is published as
 * `ai_governance`, matching what the provider asks for.
 *
 * Only file names are read here; a config file is executed when its section is
 * first requested. An extension nobody configures costs one `glob()`.
 *
 * Boot-time only; runs after discovery, before the extension register phase.
 */
#[Internal]
final class ExtensionConfigPublisher
{
    public static function publish(
        ExtensionBootstrap $bootstrap,
        ?string $appConfigPath,
        ContainerInterface $container,
    ): void {
        /** @var array<string, string> $files */
        $files = [];

        foreach ($bootstrap->getManifests() as $manifest) {
            $found = glob($manifest->path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '*.php');

            if ($found === false) {
                continue;
            }

            foreach ($found as $file) {
                $name = basename($file, '.php');
                $hostFile = $appConfigPath === null
                    ? null
                    : $appConfigPath . DIRECTORY_SEPARATOR . $name . '.php';

                $files[str_replace('-', '_', $name)] = $hostFile !== null && is_file($hostFile)
                    ? $hostFile
                    : $file;
            }
        }

        $container->instance(ExtensionConfigRegistry::class, new ExtensionConfigRegistry($files));
    }
}
