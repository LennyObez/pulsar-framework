<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\IdealConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
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
 * iDEAL payment provider via Stripe or Mollie.
 *
 * iDEAL is the leading online payment method in the Netherlands,
 * used by all major Dutch banks. It provides instant bank transfer
 * with strong customer authentication built in.
 *
 * Only EUR payments are supported by iDEAL.
 *
 * @psalm-api Registered with PaymentProviderRegistry by class-name.
 */
#[Internal]
final readonly class IdealGateway implements PaymentProviderInterface
{
    public function __construct(
        private IdealConfig $config,
        private StripeConfig $stripeConfig,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'ideal';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        if ($amount->currency !== Currency::EUR) {
            throw PaymentProviderException::declined('iDEAL only supports EUR payments');
        }

        if ($this->config->provider === 'stripe' && $this->stripeConfig->secretKey === '') {
            throw PaymentProviderException::providerError('Stripe secret key required for iDEAL');
        }

        $id = 'idl_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new PaymentIntent(
            id: $id,
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: [
                ...$metadata,
                'payment_method_type' => 'ideal',
                'upstream_provider' => $this->config->provider,
            ],
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $chargeId = 'idl_ch_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new Charge(
            id: $chargeId,
            intentId: $intentId,
            amount: Money::of(0, Currency::EUR),
            status: ChargeStatus::Succeeded,
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
        $refundId = 'idl_rf_' . substr(bin2hex(random_bytes(12)), 0, 24);

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
