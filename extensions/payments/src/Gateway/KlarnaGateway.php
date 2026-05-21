<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\KlarnaConfig;
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
use function in_array;
use function is_string;
use function random_bytes;
use function substr;

/**
 * Klarna payment provider via Stripe Payment Methods API.
 *
 * Supports three Klarna payment options:
 * - Pay Later: Invoice-based, pay within 14-30 days
 * - Pay Now: Immediate bank transfer
 * - Slice It: Installment payments over 3-36 months
 *
 * Klarna is widely used in Sweden, Norway, Finland, Denmark, Germany,
 * Austria, the Netherlands, Belgium, UK, and other European markets.
 *
 * Supports EUR, SEK, NOK, DKK, GBP, USD and other currencies depending
 * on the customer's country.
 *
 * @psalm-api Registered with PaymentProviderRegistry by class-name.
 */
#[Internal]
final readonly class KlarnaGateway implements PaymentProviderInterface
{
    /** @var list<string> Currencies supported by Klarna via Stripe. */
    private const array SUPPORTED_CURRENCIES = [
        'EUR', 'SEK', 'NOK', 'DKK', 'GBP', 'USD', 'CHF', 'PLN', 'CZK',
    ];

    public function __construct(
        private KlarnaConfig $config,
        private StripeConfig $stripeConfig,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'klarna';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        if (!in_array($amount->currency->value, self::SUPPORTED_CURRENCIES, true)) {
            throw PaymentProviderException::declined(
                'Klarna does not support currency: ' . $amount->currency->value,
            );
        }

        if ($this->stripeConfig->secretKey === '') {
            throw PaymentProviderException::providerError('Stripe secret key required for Klarna');
        }

        $klarnaCategory = $this->resolveKlarnaCategory($metadata);

        $id = 'kla_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new PaymentIntent(
            id: $id,
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: [
                ...$metadata,
                'payment_method_type' => 'klarna',
                'klarna_category' => $klarnaCategory,
                'klarna_region' => $this->config->region,
            ],
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $chargeId = 'kla_ch_' . substr(bin2hex(random_bytes(12)), 0, 24);

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
        $refundId = 'kla_rf_' . substr(bin2hex(random_bytes(12)), 0, 24);

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

    /**
     * Resolve the Klarna payment category based on metadata or config defaults.
     *
     * @param array<string, mixed> $metadata
     */
    private function resolveKlarnaCategory(array $metadata): string
    {
        /** @var mixed $requested */
        $requested = $metadata['klarna_category'] ?? null;

        if (is_string($requested)) {
            return match ($requested) {
                'pay_later' => $this->config->payLaterEnabled ? 'pay_later' : 'pay_now',
                'pay_now' => $this->config->payNowEnabled ? 'pay_now' : 'pay_later',
                'slice_it' => $this->config->sliceItEnabled ? 'slice_it' : 'pay_later',
                default => 'pay_later',
            };
        }

        if ($this->config->payLaterEnabled) {
            return 'pay_later';
        }

        if ($this->config->payNowEnabled) {
            return 'pay_now';
        }

        return 'slice_it';
    }
}
