<?php

declare(strict_types=1);

namespace Pulsar\Event;

use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Pulsar\Api\Api;

/**
 * Pulsar listener provider extending PSR-14 with registration methods.
 * @api
 */
#[Api(since: '1.0.0')]
interface ListenerProviderInterface extends PsrListenerProviderInterface
{
    /**
     * Register a listener for an event class.
     *
     * @param class-string $eventClass
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0, string $moduleId = ''): void;

    /**
     * Register all listeners declared by a subscriber.
     */
    public function addSubscriber(EventSubscriberInterface $subscriber, string $moduleId = ''): void;
}
