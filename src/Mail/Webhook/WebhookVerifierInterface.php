<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Api;

/**
 * Verifies the authenticity of inbound webhook requests.
 *
 * Each mail provider has a different signature scheme;
 * implementations validate provider-specific signatures.
 */
#[Api(since: '1.0.0')]
interface WebhookVerifierInterface
{
    /**
     * Verify that the webhook request has a valid signature.
     */
    public function verify(WebhookRequest $request): bool;
}
