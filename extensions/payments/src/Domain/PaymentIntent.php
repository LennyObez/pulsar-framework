<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Exception\PaymentException;

/**
 * Immutable payment intent with state machine enforcement.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PaymentIntent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public Money $amount,
        public PaymentIntentStatus $status,
        public string $provider,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
        public array $metadata = [],
    ) {}

    /**
     * Transition to a new status, validating the state machine.
     *
     * @throws PaymentException If the transition is invalid
     */
    #[NoDiscard]
    public function transitionTo(PaymentIntentStatus $newStatus): static
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw PaymentException::invalidTransition(
                'PaymentIntent',
                $this->status->value,
                $newStatus->value,
            );
        }

        return clone($this, ['status' => $newStatus]);
    }
}
