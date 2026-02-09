<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;

/**
 * Status of webhook processing.
 */
#[Api(since: '1.0.0')]
enum WebhookProcessingStatus
{
    case Processed;
    case Replay;
    case InvalidSignature;
    case HandlerError;
}
