<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CreatePaymentIntent;

use Pulsar\Extension\Payments\Domain\PaymentIntent;

/**
 * Result DTO for creating a payment intent.
 */
final readonly class CreatePaymentIntentResult
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public PaymentIntent $intent,
        public bool $replayed = false,
    ) {}
}
