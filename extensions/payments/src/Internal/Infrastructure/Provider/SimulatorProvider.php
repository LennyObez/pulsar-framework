<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Infrastructure\Provider;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;

use function substr;

/**
 * Deterministic test vector provider for integration testing.
 *
 * Test vectors (by amount in minor units):
 * - 9999: Decline (insufficient_funds)
 * - 9998: Decline (card_expired)
 * - 9997: Decline (card_declined)
 * - 9996: Decline (processing_error)
 * - 9995: Decline (fraud_suspected)
 * - 9994: Timeout exception
 * - 9993: Network error exception
 * - 9992: Rate limited exception
 * - 4242: Success, then dispute after capture
 * - 3030: Success, but refund fails
 * - All others: Success
 */
#[Internal]
final class SimulatorProvider implements PaymentProviderInterface
{
    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<string, Charge> */
    private array $charges = [];

    /** @var array<string, Refund> */
    private array $refunds = [];

    /** @var array<string, bool> chargeId => dispute flag for 4242 amounts */
    private array $disputeFlags = [];

    /** @var array<string, bool> chargeId => refund-fail flag for 3030 amounts */
    private array $refundFailFlags = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[Override]
    public function name(): string
    {
        return 'simulator';
    }

    #[Override]
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        $this->applyTestVector($amount->amount);

        $id = self::generateId('create_intent', $idempotencyKey);
        $intent = new PaymentIntent(
            id: $id,
            amount: $amount,
            status: PaymentIntentStatus::Created,
            provider: $this->name(),
            idempotencyKey: $idempotencyKey,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );

        $this->intents[$id] = $intent;

        return $intent;
    }

    #[Override]
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $intent = $this->intents[$intentId] ?? throw PaymentException::notFound('PaymentIntent', $intentId);

        $chargeId = self::generateId('capture_intent', $idempotencyKey, $intentId);
        $charge = new Charge(
            id: $chargeId,
            intentId: $intentId,
            amount: $intent->amount,
            status: ChargeStatus::Succeeded,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );

        $this->charges[$chargeId] = $charge;
        $this->intents[$intentId] = $intent->transitionTo(PaymentIntentStatus::Captured);

        // Flag for dispute if 4242
        if ($intent->amount->amount === 4242) {
            $this->disputeFlags[$chargeId] = true;
        }

        // Flag for refund failure if 3030
        if ($intent->amount->amount === 3030) {
            $this->refundFailFlags[$chargeId] = true;
        }

        return $charge;
    }

    #[Override]
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent
    {
        $intent = $this->intents[$intentId] ?? throw PaymentException::notFound('PaymentIntent', $intentId);

        $cancelled = $intent->transitionTo(PaymentIntentStatus::Cancelled);
        $this->intents[$intentId] = $cancelled;

        return $cancelled;
    }

    #[Override]
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        $charge = $this->charges[$chargeId] ?? throw PaymentException::notFound('Charge', $chargeId);

        if (isset($this->refundFailFlags[$chargeId])) {
            throw PaymentProviderException::refundFailed('simulated_refund_failure');
        }

        $refundAmount = $amount ?? $charge->amount;
        $refundId = self::generateId('refund', $idempotencyKey, $chargeId);
        $refund = new Refund(
            id: $refundId,
            chargeId: $chargeId,
            amount: $refundAmount,
            status: RefundStatus::Succeeded,
            provider: $this->name(),
            createdAt: $this->clock->now(),
        );

        $this->refunds[$refundId] = $refund;

        return $refund;
    }

    #[Override]
    public function getIntent(string $intentId): PaymentIntent
    {
        return $this->intents[$intentId] ?? throw PaymentException::notFound('PaymentIntent', $intentId);
    }

    #[Override]
    public function getCharge(string $chargeId): Charge
    {
        return $this->charges[$chargeId] ?? throw PaymentException::notFound('Charge', $chargeId);
    }

    #[Override]
    public function getRefund(string $refundId): Refund
    {
        return $this->refunds[$refundId] ?? throw PaymentException::notFound('Refund', $refundId);
    }

    /**
     * Check if a charge has been flagged for dispute (4242 test vector).
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function hasDisputeFlag(string $chargeId): bool
    {
        return isset($this->disputeFlags[$chargeId]);
    }

    /**
     * @throws PaymentProviderException On test vector amounts
     */
    private function applyTestVector(int $amount): void
    {
        match ($amount) {
            9999 => throw PaymentProviderException::declined('insufficient_funds'),
            9998 => throw PaymentProviderException::declined('card_expired'),
            9997 => throw PaymentProviderException::declined('card_declined'),
            9996 => throw PaymentProviderException::declined('processing_error'),
            9995 => throw PaymentProviderException::declined('fraud_suspected'),
            9994 => throw PaymentProviderException::timeout(),
            9993 => throw PaymentProviderException::networkError(),
            9992 => throw PaymentProviderException::rateLimited(),
            default => null,
        };
    }

    private static function generateId(string $operation, string $idempotencyKey, string $resourceId = ''): string
    {
        $input = 'simulator:' . $operation . ':' . $idempotencyKey . ':' . $resourceId;

        return substr(hash('sha256', $input), 0, 32);
    }
}
