<?php

declare(strict_types=1);

namespace Pulsar\Testing\Factory;

use Pulsar\Api\Api;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * Static map from entity classes to their factory classes.
 *
 * The map is generated deterministically from module manifests by the
 * `make:factory-map` command. It is committed to version control as the
 * canonical source; CI diff-checks regenerated map against committed version.
 *
 * No runtime directory scanning, reflection, or class-name guessing.
 */
#[Api(since: '1.0.0')]
final readonly class FactoryMap
{
    /**
     * @param array<class-string, class-string<Factory>> $map Entity class => Factory class
     */
    public function __construct(
        private array $map = [],
    ) {}

    /**
     * Resolve the factory class for a given entity.
     *
     * @param class-string $entityClass
     *
     * @return class-string<Factory>
     *
     * @throws RuntimeException If no factory is registered for the entity
     */
    public function resolve(string $entityClass): string
    {
        if (!array_key_exists($entityClass, $this->map)) {
            throw new RuntimeException(sprintf(
                'No factory registered for entity [%s]. Run `make:factory-map` to regenerate the factory map.',
                $entityClass,
            ));
        }

        return $this->map[$entityClass];
    }

    /**
     * Check if a factory is registered for the given entity.
     *
     * @param class-string $entityClass
     */
    public function has(string $entityClass): bool
    {
        return array_key_exists($entityClass, $this->map);
    }

    /**
     * Get the full entity-to-factory map.
     *
     * @return array<class-string, class-string<Factory>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
