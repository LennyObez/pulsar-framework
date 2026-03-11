<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CancelPaymentIntent;

/**
 * Request DTO for cancelling a payment intent.
 */
final readonly class CancelPaymentIntentRequest
{
    public function __construct(
        public string $intentId,
        public string $idempotencyKey,
    ) {}
}
