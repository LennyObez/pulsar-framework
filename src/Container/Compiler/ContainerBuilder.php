<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiler;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Container\ServiceDefinition;
use Pulsar\Container\Tag\TagCollector;

use function array_keys;
use function ksort;
use function sort;

use const SORT_STRING;

/**
 * Mutable collection of service definitions for compiler pass processing.
 *
 * Compiler passes read and modify definitions through this builder.
 * All definitions are sorted by service ID for deterministic output.
 */
#[Internal]
final class ContainerBuilder
{
    /** @var array<string, ServiceDefinition> */
    private array $definitions = [];

    /**
     * Get a definition by service ID.
     */
    #[NoDiscard]
    public function getDefinition(string $id): ?ServiceDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /**
     * Set or replace a service definition.
     */
    public function setDefinition(string $id, ServiceDefinition $definition): void
    {
        $this->definitions[$id] = $definition;
    }

    /**
     * Remove a service definition.
     */
    public function removeDefinition(string $id): void
    {
        unset($this->definitions[$id]);
    }

    /**
     * Check if a definition exists.
     */
    #[NoDiscard]
    public function hasDefinition(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    /**
     * Get all definitions sorted by service ID for determinism.
     *
     * @return array<string, ServiceDefinition>
     */
    #[NoDiscard]
    public function allDefinitions(): array
    {
        $sorted = $this->definitions;
        ksort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * Find all service IDs tagged with the given tag name.
     *
     * Returns IDs sorted by (priority DESC, id ASC) for determinism.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function findTaggedServiceIds(string $tag): array
    {
        return TagCollector::collectIds($tag, $this->definitions);
    }

    /**
     * Get all service IDs.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function getServiceIds(): array
    {
        $ids = array_keys($this->definitions);
        sort($ids, SORT_STRING);

        /** @var list<string> */
        return $ids;
    }
}
