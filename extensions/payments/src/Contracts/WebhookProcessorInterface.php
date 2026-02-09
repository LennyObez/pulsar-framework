<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Http\Response;

/**
 * Webhook processor port — verifies, deduplicates, and dispatches webhook events.
 */
#[Api(since: '1.0.0')]
interface WebhookProcessorInterface
{
    /**
     * Process an incoming webhook request.
     *
     * @param string $rawBody Raw request body bytes
     * @param string $signatureHeader Signature header value
     */
    public function process(string $rawBody, string $signatureHeader): Response;
}
