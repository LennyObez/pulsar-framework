<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Webhook event types.
 */
#[Api(since: '1.0.0')]
enum WebhookEventType: string
{
    case PaymentIntentCreated = 'payment_intent.created';
    case PaymentIntentCaptured = 'payment_intent.captured';
    case PaymentIntentCancelled = 'payment_intent.cancelled';
    case ChargeFailed = 'charge.failed';
    case RefundCreated = 'refund.created';
    case RefundFailed = 'refund.failed';
    case DisputeOpened = 'dispute.opened';
    case DisputeWon = 'dispute.won';
    case DisputeLost = 'dispute.lost';
}
