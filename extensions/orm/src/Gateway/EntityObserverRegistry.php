<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Gateway;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Contracts\EntityObserverInterface;
use Pulsar\Extension\Orm\Domain\EntityEvent;

/**
 * Registry for entity lifecycle observers.
 *
 * Observers are registered per entity class. When a lifecycle event
 * fires, all registered observers for that entity class are notified
 * in registration order.
 */
#[Api(since: '1.0.0')]
final class EntityObserverRegistry
{
    /** @var array<class-string, list<EntityObserverInterface>> */
    private array $observers = [];

    /**
     * Register an observer for the given entity class.
     *
     * @param class-string $entityClass
     */
    public function register(string $entityClass, EntityObserverInterface $observer): void
    {
        $this->observers[$entityClass][] = $observer;
    }

    /**
     * Dispatch a lifecycle event to all registered observers.
     *
     * @param class-string $entityClass
     * @return bool False if any pre-event observer vetoed the operation
     */
    public function dispatch(string $entityClass, object $entity, EntityEvent $event): bool
    {
        $observers = $this->observers[$entityClass] ?? [];

        foreach ($observers as $observer) {
            $result = $observer->handle($entity, $event);

            if (!$result && $event->isBefore()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether any observers are registered for the given class.
     *
     * @param class-string $entityClass
     */
    public function hasObservers(string $entityClass): bool
    {
        return isset($this->observers[$entityClass]) && $this->observers[$entityClass] !== [];
    }

    /**
     * Remove all observers for a specific entity class.
     *
     * @param class-string $entityClass
     */
    public function clearFor(string $entityClass): void
    {
        unset($this->observers[$entityClass]);
    }

    /**
     * Remove all registered observers.
     */
    public function clear(): void
    {
        $this->observers = [];
    }
}
