<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Api;

/**
 * Handles inbound webhook requests from mail providers.
 */
#[Api(since: '1.0.0')]
interface WebhookHandlerInterface
{
    public function handle(WebhookRequest $request): WebhookResult;
}
