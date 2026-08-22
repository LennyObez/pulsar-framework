<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Exception\PaymentException;

use function bin2hex;
use function random_bytes;

/**
 * Immutable payment entity with state machine enforcement.
 *
 * Represents a one-time or recurring payment through any gateway.
 * State transitions are validated against the PaymentStatus state machine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Payment
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public Money $amount,
        public PaymentStatus $status,
        public PaymentMethod $method,
        public string $gateway,
        public string $customerId,
        public ?string $subscriptionId,
        public ?string $invoiceId,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
        public ?string $failureReason = null,
        public array $metadata = [],
    ) {}

    /**
     * Create a new payment.
     *
     * @param array<string, mixed> $metadata
     */
    #[NoDiscard]
    public static function create(
        Money $amount,
        PaymentMethod $method,
        string $gateway,
        string $customerId,
        string $idempotencyKey,
        ?string $subscriptionId = null,
        ?string $invoiceId = null,
        array $metadata = [],
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            amount: $amount,
            status: PaymentStatus::Pending,
            method: $method,
            gateway: $gateway,
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            invoiceId: $invoiceId,
            idempotencyKey: $idempotencyKey,
            createdAt: new DateTimeImmutable(),
            metadata: $metadata,
        );
    }

    /**
     * Transition to a new status, validating the state machine.
     *
     * @throws PaymentException If the transition is invalid
     */
    #[NoDiscard]
    public function transitionTo(PaymentStatus $newStatus, ?string $failureReason = null): static
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw PaymentException::invalidTransition(
                'Payment',
                $this->status->value,
                $newStatus->value,
            );
        }

        return clone($this, [
            'status' => $newStatus,
            'failureReason' => $failureReason ?? $this->failureReason,
        ]);
    }
}
