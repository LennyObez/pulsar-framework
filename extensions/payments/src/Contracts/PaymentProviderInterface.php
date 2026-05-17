<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;

/**
 * Provider adapter contract for payment processing.
 *
 * Implementations wrap vendor-specific APIs behind this uniform interface.
 * Idempotency keys pass through to providers (belt-and-suspenders with the gateway).
 * @api
 */
#[Api(since: '1.0.0')]
interface PaymentProviderInterface
{
    /**
     * Get the provider name.
     */
    public function name(): string;

    /**
     * Create a payment intent.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws PaymentProviderException
     */
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent;

    /**
     * Capture a previously created intent.
     *
     * @throws PaymentProviderException
     */
    public function captureIntent(string $intentId, string $idempotencyKey): Charge;

    /**
     * Cancel a previously created intent.
     *
     * @throws PaymentProviderException
     */
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent;

    /**
     * Refund a charge (full or partial).
     *
     * @throws PaymentProviderException
     */
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund;

    /**
     * Get an existing payment intent.
     *
     * @throws PaymentProviderException
     */
    public function getIntent(string $intentId): PaymentIntent;

    /**
     * Get an existing charge.
     *
     * @throws PaymentProviderException
     */
    public function getCharge(string $chargeId): Charge;

    /**
     * Get an existing refund.
     *
     * @throws PaymentProviderException
     */
    public function getRefund(string $refundId): Refund;
}
