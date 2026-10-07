<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\Ticket;

/**
 * Dispatched when a ticket is assigned to an agent.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TicketAssigned
{
    public function __construct(
        public Ticket $ticket,
        public ?string $previousAssigneeId,
    ) {}
}
