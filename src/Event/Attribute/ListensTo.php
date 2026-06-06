<?php

declare(strict_types=1);

namespace Pulsar\Event\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a subscriber method as listening to a specific event.
 *
 * Alternative to implementing getSubscribedEvents(): allows
 * attribute-based event subscription on individual methods.
 *
 * Usage:
 *   class OrderSubscriber
 *   {
 *       #[ListensTo(OrderCreated::class, priority: 10)]
 *       public function onOrderCreated(OrderCreated $event): void { }
 *
 *       #[ListensTo(OrderShipped::class)]
 *       public function onOrderShipped(OrderShipped $event): void { }
 *   }
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0.0')]
final readonly class ListensTo
{
    /**
     * @param class-string $event The event class to listen for
     * @param int $priority Higher = earlier execution (default 0)
     */
    public function __construct(
        public string $event,
        public int $priority = 0,
    ) {}
}
