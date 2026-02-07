<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CreatePaymentIntent;

use Pulsar\Extension\Payments\Domain\PaymentIntent;

/**
 * Result DTO for creating a payment intent.
 */
final readonly class CreatePaymentIntentResult
{
    public function __construct(
        public PaymentIntent $intent,
        public bool $replayed = false,
    ) {}
}
