<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;

/**
 * Dispatched when a message is added to a ticket.
 */
#[Api(since: '1.0.0')]
final readonly class TicketMessageAdded
{
    public function __construct(
        public Ticket $ticket,
        public TicketMessage $message,
    ) {}
}
