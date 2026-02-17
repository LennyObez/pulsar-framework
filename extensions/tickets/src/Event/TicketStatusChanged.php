<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketStatus;

/**
 * Dispatched when a ticket's status changes.
 */
#[Api(since: '1.0.0')]
final readonly class TicketStatusChanged
{
    public function __construct(
        public Ticket $ticket,
        public TicketStatus $previousStatus,
    ) {}
}
