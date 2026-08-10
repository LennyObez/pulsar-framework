<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Idempotency\Exception\IdempotencyException;

/**
 * Payment gateway port: orchestrates provider calls with cross-cutting concerns.
 *
 * Implementation-detail exceptions (JsonException from
 * (de)serialisation, SodiumException from envelope signing) are wrapped
 * by the implementation into `IdempotencyException::serializationFailed()`
 * so consumers only need to catch the two domain exceptions declared
 * below.
 * @api
 */
#[Api(since: '1.0.0')]
interface PaymentGatewayInterface
{
    /**
     * Create a payment intent with idempotency enforcement.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent;

    /**
     * Capture a payment intent with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function captureIntent(string $intentId, string $idempotencyKey): Charge;

    /**
     * Cancel a payment intent with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent;

    /**
     * Refund a charge with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund;

    /**
     * Get a payment intent (read-only, no idempotency).
     *
     * @throws PaymentProviderException
     */
    public function getIntent(string $intentId): PaymentIntent;

    /**
     * Get a charge (read-only, no idempotency).
     *
     * @throws PaymentProviderException
     */
    public function getCharge(string $chargeId): Charge;

    /**
     * Get a refund (read-only, no idempotency).
     *
     * @throws PaymentProviderException
     */
    public function getRefund(string $refundId): Refund;
}
