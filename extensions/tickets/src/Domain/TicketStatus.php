<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use Pulsar\Api\Api;

/**
 * Ticket lifecycle status with state-machine transitions.
 * @api
 */
#[Api(since: '1.0.0')]
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingOnCustomer = 'waiting_on_customer';
    case WaitingOnAgent = 'waiting_on_agent';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';

    /**
     * Whether a transition from this status to the target is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::Open => match ($target) {
                self::InProgress, self::WaitingOnCustomer, self::Resolved, self::Closed => true,
                default => false,
            },
            self::InProgress => match ($target) {
                self::WaitingOnCustomer, self::WaitingOnAgent, self::Resolved, self::Closed => true,
                default => false,
            },
            self::WaitingOnCustomer => match ($target) {
                self::InProgress, self::WaitingOnAgent, self::Resolved, self::Closed => true,
                default => false,
            },
            self::WaitingOnAgent => match ($target) {
                self::InProgress, self::WaitingOnCustomer, self::Resolved, self::Closed => true,
                default => false,
            },
            self::Resolved => match ($target) {
                self::Closed, self::Reopened => true,
                default => false,
            },
            self::Closed => match ($target) {
                self::Reopened => true,
                default => false,
            },
            self::Reopened => match ($target) {
                self::InProgress, self::WaitingOnCustomer, self::WaitingOnAgent, self::Resolved, self::Closed => true,
                default => false,
            },
        };
    }

    /**
     * Whether the ticket is considered active (not yet resolved or closed).
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Resolved, self::Closed => false,
            default => true,
        };
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::WaitingOnCustomer => 'Waiting on Customer',
            self::WaitingOnAgent => 'Waiting on Agent',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
        };
    }

    /**
     * CSS class suffix for status badges.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'primary',
            self::WaitingOnCustomer => 'warning',
            self::WaitingOnAgent => 'warning',
            self::Resolved => 'success',
            self::Closed => 'secondary',
            self::Reopened => 'info',
        };
    }
}
