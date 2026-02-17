<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\OrderPlaced;
use Pulsar\Event\Attribute\ListensTo;

/**
 * Synchronous listener that updates inventory when an order is placed.
 *
 * Unlike SendOrderConfirmation, this listener runs synchronously
 * (no #[ShouldQueue]) because inventory updates must complete
 * before the response is sent to prevent overselling.
 *
 * Demonstrates listener priority: this runs at priority 100 (before
 * SendOrderConfirmation at priority 10), ensuring inventory is
 * decremented before confirmation emails are queued.
 */
final class UpdateInventory
{
    #[ListensTo(OrderPlaced::class, priority: 100)]
    public function handle(OrderPlaced $event): void
    {
        echo sprintf(
            "[UpdateInventory] Decremented stock for order #%d\n",
            $event->orderId,
        );
    }
}
