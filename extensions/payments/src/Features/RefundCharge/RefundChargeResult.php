<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\RefundCharge;

use Pulsar\Extension\Payments\Domain\Refund;

/**
 * Result DTO for refunding a charge.
 */
final readonly class RefundChargeResult
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public Refund $refund,
        public bool $replayed = false,
    ) {}
}
