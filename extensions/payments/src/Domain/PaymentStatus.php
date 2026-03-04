<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Payment lifecycle status.
 *
 * State machine:
 *   Pending --complete--> Completed --refund--> Refunded
 *   Pending --complete--> Completed --partial_refund--> PartiallyRefunded --refund--> Refunded
 *   Pending --fail--> Failed
 *   Pending --cancel--> Cancelled
 */
#[Api(since: '1.0.0')]
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Check if transitioning to the given status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Completed
                || $target === self::Failed
                || $target === self::Cancelled,
            self::Completed => $target === self::Refunded
                || $target === self::PartiallyRefunded,
            self::PartiallyRefunded => $target === self::Refunded,
            self::Failed, self::Cancelled, self::Refunded => false,
        };
    }
}
