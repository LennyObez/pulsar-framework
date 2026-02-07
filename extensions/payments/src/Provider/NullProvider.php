<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Provider;

use Override;
use Pulsar\Extension\Payments\Contract\ClockInterface;
use Pulsar\Extension\Payments\Contract\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;

use function substr;

/**
 * No-op success provider for testing and development.
 *
 * All operations succeed immediately with deterministic IDs.
 */
final readonly class NullProvider implements PaymentProviderInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'null';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        return new PaymentIntent(
            id: self::generateId('create_intent', $idempotencyKey),
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        return new Charge(
            id: self::generateId('capture_intent', $idempotencyKey, $intentId),
            intentId: $intentId,
            amount: Money::of(0, Currency::USD),
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
            amount: Money::of(0, Currency::USD),
            status: PaymentIntentStatus::Cancelled,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
        );
    }

    #[Override]
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        return new Refund(
            id: self::generateId('refund', $idempotencyKey, $chargeId),
            chargeId: $chargeId,
            amount: $amount ?? Money::of(0, Currency::USD),
            status: RefundStatus::Succeeded,
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

    private static function generateId(string $operation, string $idempotencyKey, string $resourceId = ''): string
    {
        $input = 'null:' . $operation . ':' . $idempotencyKey . ':' . $resourceId;

        return substr(hash('sha256', $input), 0, 32);
    }
}
