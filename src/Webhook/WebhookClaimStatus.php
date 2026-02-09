<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Status of a webhook event claim.
 */
#[Api(since: '1.0.0')]
enum WebhookClaimStatus
{
    case Replay;
    case Claimed;
}
