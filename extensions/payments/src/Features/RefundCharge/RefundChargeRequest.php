<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\RefundCharge;

use Pulsar\Extension\Payments\Domain\Money;

/**
 * Request DTO for refunding a charge.
 *
 * Pass `amount = null` for a full refund.
 */
final readonly class RefundChargeRequest
{
    public function __construct(
        public string $chargeId,
        public ?Money $amount,
        public string $idempotencyKey,
    ) {}
}
