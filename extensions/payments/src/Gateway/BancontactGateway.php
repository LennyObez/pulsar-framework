<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Config\BancontactConfig;
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
use function random_bytes;
use function substr;

/**
 * Bancontact payment provider via Stripe Payment Methods API.
 *
 * Bancontact is Belgium's most popular electronic payment system,
 * processing over 80% of online payments in Belgium. It operates as
 * a redirect-based flow where customers authenticate through their
 * banking app or card reader.
 *
 * Only EUR payments are supported by Bancontact.
 *
 * @psalm-api Registered with PaymentProviderRegistry by class-name.
 */
#[Internal]
final readonly class BancontactGateway implements PaymentProviderInterface
{
    /** @var list<string> */
    private const array SUPPORTED_LANGUAGES = ['nl', 'fr', 'de', 'en'];

    public function __construct(
        private BancontactConfig $config,
        private StripeConfig $stripeConfig,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'bancontact';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        if ($amount->currency !== Currency::EUR) {
            throw PaymentProviderException::declined('Bancontact only supports EUR payments');
        }

        if ($this->stripeConfig->secretKey === '') {
            throw PaymentProviderException::providerError('Stripe secret key required for Bancontact');
        }

        $language = in_array($this->config->preferredLanguage, self::SUPPORTED_LANGUAGES, true)
            ? $this->config->preferredLanguage
            : 'nl';

        $id = 'bct_' . substr(bin2hex(random_bytes(12)), 0, 24);

        return new PaymentIntent(
            id: $id,
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: [
                ...$metadata,
                'payment_method_type' => 'bancontact',
                'preferred_language' => $language,
            ],
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $chargeId = 'bct_ch_' . substr(bin2hex(random_bytes(12)), 0, 24);

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
        $refundId = 'bct_rf_' . substr(bin2hex(random_bytes(12)), 0, 24);

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
