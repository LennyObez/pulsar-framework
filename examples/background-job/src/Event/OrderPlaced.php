<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Domain event dispatched when an order is placed.
 *
 * Pulsar events are plain PHP objects. They carry the data needed
 * by listeners. Events are dispatched via EventDispatcherInterface
 * and can be handled synchronously or asynchronously via #[ShouldQueue].
 */
final readonly class OrderPlaced
{
    public function __construct(
        public int $orderId,
        public string $customerEmail,
        public float $total,
        public string $currency = 'USD',
    ) {}
}
