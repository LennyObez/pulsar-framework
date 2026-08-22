<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Pulsar\Api\Api;

/**
 * Lifecycle states for a mobile app subscription.
 *
 * - Active: subscription is paid and current
 * - Expired: subscription period ended without renewal
 * - GracePeriod: renewal failed but the store is retrying within a grace window
 * - Cancelled: user explicitly cancelled; access may continue until expiry
 * - BillingRetry: payment declined, store retrying outside grace period
 * - Revoked: store revoked access (e.g. refund, policy violation)
 * @api
 */
#[Api(since: '1.0.0')]
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case GracePeriod = 'grace_period';
    case Cancelled = 'cancelled';
    case BillingRetry = 'billing_retry';
    case Revoked = 'revoked';
}
