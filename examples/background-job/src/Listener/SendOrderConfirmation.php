<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\OrderPlaced;
use Pulsar\Event\Attribute\ListensTo;
use Pulsar\Event\Attribute\ShouldQueue;

/**
 * Event listener that sends an order confirmation email.
 *
 * Demonstrates:
 * - #[ListensTo] for attribute-based event subscription
 * - #[ShouldQueue] for asynchronous processing via the queue
 * - Listeners receive the event object as their only parameter
 *
 * Without #[ShouldQueue], the listener runs synchronously during
 * the request. With it, the listener is dispatched as a background
 * job and processed by the queue worker.
 */
final class SendOrderConfirmation
{
    /**
     * Handle the OrderPlaced event by sending a confirmation email.
     *
     * This runs asynchronously on the 'notifications' queue.
     */
    #[ListensTo(OrderPlaced::class, priority: 10)]
    #[ShouldQueue(queue: 'notifications', maxRetries: 3)]
    public function handle(OrderPlaced $event): void
    {
        // In a real application, inject MailerInterface and send:
        //
        //   $this->mailer->send(
        //       to: $event->customerEmail,
        //       subject: "Order #{$event->orderId} Confirmed",
        //       body: "Your order of {$event->total} {$event->currency} has been placed.",
        //   );
        //
        // For this example, we log to stdout:
        echo sprintf(
            "[SendOrderConfirmation] Sending confirmation for order #%d to %s (total: %.2f %s)\n",
            $event->orderId,
            $event->customerEmail,
            $event->total,
            $event->currency,
        );
    }
}
