<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Order lifecycle status tracking from cart creation through fulfillment.
 */
#[Api(since: '1.0.0')]
enum OrderStatus: string
{
    case Cart = 'cart';
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Fulfilled = 'fulfilled';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this is a terminal status from which no further transitions are possible.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Refunded, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Cart',
            self::PendingPayment => 'Pending Payment',
            self::Confirmed => 'Confirmed',
            self::Fulfilled => 'Fulfilled',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
