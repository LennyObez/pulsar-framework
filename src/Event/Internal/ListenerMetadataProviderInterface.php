<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use Pulsar\Api\Internal;

/**
 * Internal introspection interface for listener metadata.
 *
 * Exposes per-event-class information needed by EventDispatcher for
 * scope computation, envelope enforcement, and storm override resolution
 * without reflection or container lookups.
 */
#[Internal(reason: 'Used internally by EventDispatcher for scope/enforcement decisions')]
interface ListenerMetadataProviderInterface
{
    /**
     * Get the module IDs of all listeners registered for the given event class.
     *
     * @param class-string $eventClass
     * @return list<string>
     */
    public function listenerModuleIdsFor(string $eventClass): array;

    /**
     * Get the storm override maxDepth for the given event class, if any.
     *
     * @param class-string $eventClass
     */
    public function stormOverrideFor(string $eventClass): ?int;

    /**
     * Check if the given event class requires envelope-based dispatch.
     *
     * @param class-string $eventClass
     */
    public function requiresEnvelopeFor(string $eventClass): bool;
}
