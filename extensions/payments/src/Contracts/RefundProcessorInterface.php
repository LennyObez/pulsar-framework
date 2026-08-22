<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Refund;

/**
 * Refund processing contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface RefundProcessorInterface
{
    /**
     * Process a full refund for a charge.
     */
    public function refundFull(string $chargeId, string $reason): Refund;

    /**
     * Process a partial refund for a charge.
     */
    public function refundPartial(string $chargeId, Money $amount, string $reason): Refund;

    /**
     * Retrieve a refund by ID.
     */
    public function get(string $refundId): ?Refund;
}
