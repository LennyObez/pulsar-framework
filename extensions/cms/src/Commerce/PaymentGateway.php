<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Payment provider abstraction for commerce operations.
 * @api
 */
#[Api(since: '1.0.0')]
interface PaymentGateway
{
    /**
     * Create a payment intent with the external provider.
     *
     * @param int $amount Amount in minor currency units
     * @param string $currency ISO 4217 currency code
     * @param string $idempotencyKey Unique key to prevent duplicate charges
     * @param array<string, mixed> $metadata Additional data for the payment provider
     */
    public function createPaymentIntent(
        int $amount,
        string $currency,
        string $idempotencyKey,
        array $metadata = [],
    ): PaymentResult;

    /**
     * Process a refund against a previous payment.
     *
     * @param string $paymentIntentId The original payment intent reference
     * @param int $amount Refund amount in minor currency units
     * @param string $reason Reason for the refund
     */
    public function refund(string $paymentIntentId, int $amount, string $reason): PaymentResult;

    /**
     * Verify a webhook signature from the payment provider.
     *
     * @param string $payload Raw webhook request body
     * @param string $signature Signature header value
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
