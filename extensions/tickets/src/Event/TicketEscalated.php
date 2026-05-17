<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\EscalationRule;
use Pulsar\Extension\Tickets\Domain\Ticket;

/**
 * Dispatched when a ticket is escalated due to SLA breach.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TicketEscalated
{
    public function __construct(
        public Ticket $ticket,
        public EscalationRule $triggeredRule,
        public int $elapsedMinutes,
    ) {}
}
