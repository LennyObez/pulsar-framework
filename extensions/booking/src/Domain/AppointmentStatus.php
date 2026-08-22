<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use Pulsar\Api\Api;

use function in_array;

/**
 * Appointment lifecycle status.
 *
 * State machine:
 *   Requested --> Confirmed --> DepositPaid --> Reminded --> InProgress --> Completed
 *   Requested --> Cancelled
 *   Confirmed --> Cancelled
 *   DepositPaid --> Cancelled
 *   Confirmed --> Rescheduled
 *   DepositPaid --> Rescheduled
 *   Reminded --> InProgress --> NoShow
 *   InProgress --> NoShow
 * @api
 */
#[Api(since: '1.0.0')]
enum AppointmentStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case DepositPaid = 'deposit_paid';
    case Reminded = 'reminded';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Rescheduled = 'rescheduled';

    /**
     * Check if transitioning to the given status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Requested => in_array($target, [self::Confirmed, self::Cancelled], true),
            self::Confirmed => in_array($target, [self::DepositPaid, self::Reminded, self::InProgress, self::Cancelled, self::Rescheduled], true),
            self::DepositPaid => in_array($target, [self::Reminded, self::InProgress, self::Cancelled, self::Rescheduled], true),
            self::Reminded => in_array($target, [self::InProgress, self::Cancelled, self::NoShow], true),
            self::InProgress => in_array($target, [self::Completed, self::NoShow], true),
            self::Completed, self::Cancelled, self::NoShow, self::Rescheduled => false,
        };
    }

    /**
     * Whether this status represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::NoShow, self::Rescheduled => true,
            default => false,
        };
    }

    /**
     * Whether this status represents an active appointment.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Requested, self::Confirmed, self::DepositPaid, self::Reminded, self::InProgress => true,
            default => false,
        };
    }
}
