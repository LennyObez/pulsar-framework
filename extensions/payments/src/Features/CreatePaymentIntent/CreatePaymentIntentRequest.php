<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CreatePaymentIntent;

use Pulsar\Extension\Payments\Domain\Money;

/**
 * Request DTO for creating a payment intent.
 */
final readonly class CreatePaymentIntentRequest
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public Money $amount,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {}
}
