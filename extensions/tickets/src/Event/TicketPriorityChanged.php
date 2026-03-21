<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;

/**
 * Dispatched when a ticket's priority changes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TicketPriorityChanged
{
    public function __construct(
        public Ticket $ticket,
        public TicketPriority $previousPriority,
    ) {}
}
