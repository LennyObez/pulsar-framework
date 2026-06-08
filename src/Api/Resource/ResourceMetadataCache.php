<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Cache for resolved resource metadata.
 *
 * Avoids repeated reflection on hot paths by caching the resolved
 * {@see ResourceMetadata} per class. In production, this can be
 * pre-warmed at boot time.
 */
#[Internal]
final class ResourceMetadataCache
{
    /**
     * @var array<class-string, ResourceMetadata>
     */
    private array $cache = [];

    /**
     * Get metadata for a resource class, resolving and caching on first access.
     *
     * @param class-string $class
     */
    #[NoDiscard]
    public function get(string $class): ResourceMetadata
    {
        return $this->cache[$class] ??= ResourceMetadata::resolve($class);
    }

    /**
     * Pre-warm the cache with a list of resource classes.
     *
     * @param list<class-string> $classes
     */
    public function warmUp(array $classes): void
    {
        foreach ($classes as $class) {
            $this->cache[$class] ??= ResourceMetadata::resolve($class);
        }
    }

    /**
     * Check if metadata is cached for a class.
     *
     * @param class-string $class
     */
    public function has(string $class): bool
    {
        return isset($this->cache[$class]);
    }

    /**
     * Clear the cache.
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
