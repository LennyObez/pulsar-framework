<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use Pulsar\Api\Api;

/**
 * Domain events emitted during ticket lifecycle transitions.
 * @api
 */
#[Api(since: '1.0.0')]
enum TicketEvent: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case MessageAdded = 'message_added';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Escalated = 'escalated';

    /**
     * Human-readable label for audit logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Ticket Created',
            self::Assigned => 'Ticket Assigned',
            self::StatusChanged => 'Status Changed',
            self::PriorityChanged => 'Priority Changed',
            self::MessageAdded => 'Message Added',
            self::Resolved => 'Ticket Resolved',
            self::Closed => 'Ticket Closed',
            self::Reopened => 'Ticket Reopened',
            self::Escalated => 'Ticket Escalated',
        };
    }
}
