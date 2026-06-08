<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CapturePaymentIntent;

use Pulsar\Extension\Payments\Domain\Charge;

/**
 * Result DTO for capturing a payment intent.
 */
final readonly class CapturePaymentIntentResult
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public Charge $charge,
        public bool $replayed = false,
    ) {}
}
