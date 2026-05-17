<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use Pulsar\Api\Api;

/**
 * Ticket priority levels for triage and SLA enforcement.
 * @api
 */
#[Api(since: '1.0.0')]
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
    case Critical = 'critical';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
            self::Critical => 'Critical',
        };
    }

    /**
     * CSS class suffix for priority badges.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Low => 'secondary',
            self::Normal => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
            self::Critical => 'danger',
        };
    }

    /**
     * Numeric weight for sorting (higher = more urgent).
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 2,
            self::High => 3,
            self::Urgent => 4,
            self::Critical => 5,
        };
    }
}
