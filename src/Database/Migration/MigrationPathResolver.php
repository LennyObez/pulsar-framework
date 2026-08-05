<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Internal;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionRegistry;

use function dirname;
use function glob;

use const DIRECTORY_SEPARATOR;
use const GLOB_ONLYDIR;

/**
 * Resolves migration paths from project config and registered extensions.
 *
 * The project path (from DatabaseConfig) is always first, ensuring project
 * migrations run before extension migrations. Extension paths are appended
 * only for extensions that successfully registered or booted (not failed ones).
 * This prevents disabled or broken extensions from contributing migrations
 * that could conflict with project schemas.
 */
#[Internal(reason: 'Wired in composition root; use MigrationPathResolverInterface')]
final readonly class MigrationPathResolver implements MigrationPathResolverInterface
{
    public function __construct(
        private DatabaseConfig $databaseConfig,
        private ?ExtensionRegistry $extensionRegistry = null,
    ) {}

    public function resolve(): array
    {
        // The framework's own migrations, which are neither the project's nor an
        // extension's and so belonged to neither source. `src/Auth/Database/Migration`
        // creates the tables second-factor authentication needs, and nothing discovered
        // it: `pulsar migrate` has never run a framework migration, on any installation.
        //
        // Globbed rather than listed so a core module that gains migrations is found
        // without anyone remembering to come back here.
        $paths = $this->frameworkMigrationPaths();

        $paths[] = $this->databaseConfig->migrationsPath;

        if ($this->extensionRegistry === null) {
            return $paths;
        }

        foreach ($this->extensionRegistry->allManifests() as $name => $manifest) {
            // Only include migrations from extensions that successfully
            // registered or booted. Failed extensions are excluded to
            // prevent schema conflicts with project tables.
            $state = $this->extensionRegistry->getState($name);
            if ($state === ExtensionLifecycle::Failed) {
                continue;
            }

            foreach ($manifest->provides->migrations as $relPath) {
                $paths[] = $manifest->path . DIRECTORY_SEPARATOR . $relPath;
            }
        }

        return $paths;
    }

    /**
     * Every `src/<Module>/Database/Migration` directory the framework ships.
     *
     * Order carries no meaning here, and it is worth saying why rather than leaving a
     * reader to assume it does. {@see MigrationRepository::discover()} gives sequential
     * versions a path-scoped prefix, so those cannot collide across sources at all;
     * timestamp versions — which is what the framework's own migrations use — take no
     * prefix, and two paths offering the same timestamp make `discover()` throw. There is
     * no precedence to express: a collision stops the run rather than resolving to
     * anybody's favour.
     *
     * @return list<string>
     */
    private function frameworkMigrationPaths(): array
    {
        $matches = glob(dirname(__DIR__, 2) . '/*/Database/Migration', GLOB_ONLYDIR);

        return $matches === false ? [] : $matches;
    }
}
