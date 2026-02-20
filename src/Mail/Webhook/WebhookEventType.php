<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Api;

/**
 * Types of webhook events from mail providers.
 */
#[Api(since: '1.0.0')]
enum WebhookEventType: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
    case Delivery = 'delivery';
    case Open = 'open';
    case Click = 'click';
}
