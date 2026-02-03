<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Exception\PaymentException;

/**
 * Immutable dispute record with state machine enforcement.
 */
#[Api]
final readonly class Dispute
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $chargeId,
        public Money $amount,
        public DisputeStatus $status,
        public DisputeReason $reason,
        public string $provider,
        public DateTimeImmutable $createdAt,
        public array $metadata = [],
    ) {}

    /**
     * Transition to a new status, validating the state machine.
     *
     * @throws PaymentException If the transition is invalid
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function transitionTo(DisputeStatus $newStatus): self
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw PaymentException::invalidTransition(
                'Dispute',
                $this->status->value,
                $newStatus->value,
            );
        }

        return clone($this, ['status' => $newStatus]);
    }
}
