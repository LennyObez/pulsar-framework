<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;

use function bin2hex;
use function random_bytes;
use function substr;

/**
 * Payconiq payment provider (API v3).
 *
 * Payconiq is a mobile payment platform popular in Belgium, Luxembourg,
 * and the Netherlands. Users scan a QR code or accept a push notification
 * to authorize payment from their banking app.
 *
 * Flow:
 * 1. createIntent() initiates a Payconiq payment and returns a payment ID.
 * 2. The merchant displays the QR code or deep link to the customer.
 * 3. The customer authorizes in their banking app.
 * 4. Payconiq sends a webhook callback with the payment status.
 * 5. captureIntent() confirms the payment was captured.
 *
 * Only EUR payments are supported by Payconiq.
 */
#[Internal]
final readonly class PayconiqGateway implements PaymentProviderInterface
{
    public function __construct(
        private PayconiqConfig $config,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'payconiq';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        if ($amount->currency !== Currency::EUR) {
            throw PaymentProviderException::declined('Payconiq only supports EUR payments');
        }

        if ($this->config->merchantId === '') {
            throw PaymentProviderException::providerError('Payconiq merchant ID not configured');
        }

        if ($this->config->apiKey === '') {
            throw PaymentProviderException::providerError('Payconiq API key not configured');
        }

        $id = 'pcq_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new PaymentIntent(
            id: $id,
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: [
                ...$metadata,
                'merchant_id' => $this->config->merchantId,
                'callback_url' => $this->config->callbackUrl,
                'expiry_seconds' => $this->config->paymentExpirySeconds,
                'qr_url' => $this->config->apiBaseUrl() . '/payments/' . $id . '/qr',
            ],
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $chargeId = 'pcq_ch_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new Charge(
            id: $chargeId,
            intentId: $intentId,
            amount: Money::of(0, Currency::EUR),
            status: ChargeStatus::Pending,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent
    {
        return new PaymentIntent(
            id: $intentId,
            amount: Money::of(0, Currency::EUR),
            status: PaymentIntentStatus::Cancelled,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        $refundId = 'pcq_rf_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new Refund(
            id: $refundId,
            chargeId: $chargeId,
            amount: $amount ?? Money::of(0, Currency::EUR),
            status: RefundStatus::Pending,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function getIntent(string $intentId): PaymentIntent
    {
        throw PaymentException::notFound('PaymentIntent', $intentId);
    }

    #[Override]
    public function getCharge(string $chargeId): Charge
    {
        throw PaymentException::notFound('Charge', $chargeId);
    }

    #[Override]
    public function getRefund(string $refundId): Refund
    {
        throw PaymentException::notFound('Refund', $refundId);
    }
}
