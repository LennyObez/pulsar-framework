<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Status of a webhook event claim.
 */
#[Api]
enum WebhookClaimStatus
{
    case Replay;
    case Claimed;
}
