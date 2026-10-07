<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CapturePaymentIntent;

/**
 * Request DTO for capturing a payment intent.
 */
final readonly class CapturePaymentIntentRequest
{
    public function __construct(
        public string $intentId,
        public string $idempotencyKey,
    ) {}
}
