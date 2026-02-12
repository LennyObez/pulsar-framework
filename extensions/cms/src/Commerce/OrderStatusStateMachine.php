<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

use function in_array;

/**
 * State machine governing valid order status transitions.
 *
 * Transition rules:
 * - Cart -> PendingPayment
 * - PendingPayment -> Confirmed | Failed
 * - Confirmed -> Fulfilled | Cancelled
 * - Fulfilled -> Refunded
 * - Failed -> Cancelled
 * - Any non-terminal status -> Cancelled (except already Cancelled or Refunded)
 */
#[Api(since: '1.0.0')]
final readonly class OrderStatusStateMachine
{
    /**
     * @var array<string, list<string>> Map of status value to allowed target status values
     */
    private const array TRANSITIONS = [
        'cart' => ['pending_payment', 'cancelled'],
        'pending_payment' => ['confirmed', 'failed', 'cancelled'],
        'confirmed' => ['fulfilled', 'cancelled'],
        'fulfilled' => ['refunded'],
        'failed' => ['cancelled'],
        'refunded' => [],
        'cancelled' => [],
    ];

    /**
     * Whether a transition from one status to another is valid.
     */
    public static function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Perform a status transition, returning the new status.
     *
     * @throws CmsException If the transition is not allowed
     */
    public static function transition(OrderStatus $from, OrderStatus $to): OrderStatus
    {
        if (!self::canTransition($from, $to)) {
            throw CmsException::invalidTransition($from->value, $to->value);
        }

        return $to;
    }
}
