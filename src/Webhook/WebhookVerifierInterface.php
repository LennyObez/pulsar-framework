<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use Pulsar\Api\Api;
use Pulsar\Webhook\Exception\WebhookException;

/**
 * Webhook signature verification contract.
 */
#[Api(since: '1.0.0')]
interface WebhookVerifierInterface
{
    /**
     * Verify a webhook signature.
     *
     * @param string $payload Raw request body bytes
     * @param string $signatureHeader The signature header value
     * @param string $secret The webhook signing secret
     * @param int $toleranceSeconds Maximum allowed timestamp age
     *
     * @throws WebhookException On signature mismatch or expired timestamp
     */
    public function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $toleranceSeconds,
    ): void;
}
