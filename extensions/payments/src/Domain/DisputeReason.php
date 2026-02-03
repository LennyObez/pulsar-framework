<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Dispute reason codes.
 */
#[Api]
enum DisputeReason: string
{
    case Fraudulent = 'fraudulent';
    case Duplicate = 'duplicate';
    case ProductNotReceived = 'product_not_received';
    case ProductUnacceptable = 'product_unacceptable';
    case SubscriptionCancelled = 'subscription_cancelled';
    case Unrecognized = 'unrecognized';
    case Other = 'other';
}
