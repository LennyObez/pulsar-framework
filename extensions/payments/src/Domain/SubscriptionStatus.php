<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Subscription lifecycle status.
 *
 * Covers both web-based (Stripe/PayPal/SEPA) and mobile (App Store/Google Play)
 * subscription states.
 */
#[Api(since: '1.0.0')]
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
