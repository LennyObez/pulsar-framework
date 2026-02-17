<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\EntityEvent;

/**
 * Observes entity lifecycle events.
 *
 * Register observers via EntityObserverRegistry. Each observer
 * is called with the entity and the event type when the corresponding
 * lifecycle event fires.
 *
 * For pre-events (creating/updating/deleting), returning false
 * cancels the operation.
 */
#[Api(since: '1.0.0')]
interface EntityObserverInterface
{
    /**
     * Handle an entity lifecycle event.
     *
     * @return bool Return false from a pre-event to cancel the operation
     */
    public function handle(object $entity, EntityEvent $event): bool;
}
