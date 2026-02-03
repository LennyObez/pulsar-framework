<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Payment intent lifecycle status.
 *
 * State machine:
 *   Created --capture--> Captured --dispute--> Disputed --resolve--> Resolved
 *   Created --cancel---> Cancelled
 */
#[Api]
enum PaymentIntentStatus: string
{
    case Created = 'created';
    case Captured = 'captured';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';
    case Resolved = 'resolved';

    /**
     * Check if transitioning to the given status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Created => $target === self::Captured || $target === self::Cancelled,
            self::Captured => $target === self::Disputed,
            self::Disputed => $target === self::Resolved,
            self::Cancelled, self::Resolved => false,
        };
    }
}
