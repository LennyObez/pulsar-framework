<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Metadata;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\MappingException;

/**
 * In-memory metadata registry with compile-on-first-access caching.
 */
#[Internal]
final class CachedMetadataRegistry implements MetadataRegistryInterface
{
    /** @var array<class-string, EntityMetadata> */
    private array $cache = [];

    public function __construct(
        private readonly MetadataCompiler $compiler,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    #[Override]
    public function get(string $entityClass): EntityMetadata
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }

        $metadata = $this->compiler->compile($entityClass);
        $this->cache[$entityClass] = $metadata;

        return $metadata;
    }

    #[Override]
    public function has(string $entityClass): bool
    {
        if (isset($this->cache[$entityClass])) {
            return true;
        }

        try {
            $this->get($entityClass);

            return true;
        } catch (MappingException) {
            return false;
        }
    }

    /**
     * Pre-warm the cache with the given entity classes.
     *
     * @param list<class-string> $entityClasses
     * @throws MappingException
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function warmUp(array $entityClasses): void
    {
        foreach ($entityClasses as $class) {
            $this->get($class);
        }
    }

    /**
     * Clear the metadata cache.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
