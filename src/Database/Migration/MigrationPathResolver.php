<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Internal;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionRegistry;

use const DIRECTORY_SEPARATOR;

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
        $paths = [$this->databaseConfig->migrationsPath];

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
}
