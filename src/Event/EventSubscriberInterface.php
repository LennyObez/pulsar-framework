<?php

declare(strict_types=1);

namespace Pulsar\Event;

use Pulsar\Api\Api;

/**
 * Interface for event subscribers that declare multiple event listeners.
 *
 * Subscribers return a map of event class names to [method, priority] pairs.
 * @api
 */
#[Api(since: '1.0.0')]
interface EventSubscriberInterface
{
    /**
     * Return the events this subscriber listens to.
     *
     * Each entry maps an event class to [methodName, priority]. The method
     * must be public and accept the event object as its single parameter.
     *
     * @return array<class-string, array{0: string, 1: int}> Map of event FQCN → [method, priority]
     */
    public function getSubscribedEvents(): array;
}
