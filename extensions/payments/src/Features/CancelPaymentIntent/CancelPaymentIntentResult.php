<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CancelPaymentIntent;

use Pulsar\Extension\Payments\Domain\PaymentIntent;

/**
 * Result DTO for cancelling a payment intent.
 */
final readonly class CancelPaymentIntentResult
{
    public function __construct(
        public PaymentIntent $intent,
        public bool $replayed = false,
    ) {}
}
