<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Status of webhook processing.
 */
#[Api]
enum WebhookProcessingStatus
{
    case Processed;
    case Replay;
    case InvalidSignature;
    case HandlerError;
}
