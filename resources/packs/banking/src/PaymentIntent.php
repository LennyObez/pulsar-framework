<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Payment intent entity.
 *
 * Represents the intent to make a payment. Follows the two-phase commit
 * pattern: create intent, then confirm or cancel. Supports Strong Customer
 * Authentication (SCA) flow hook points for PSD2 controls.
 */
final class PaymentIntent
{
    /**
     * @param non-empty-string      $id             Unique intent identifier
     * @param int                   $amountCents    Amount in minor currency units
     * @param non-empty-string      $currency       ISO 4217 currency code
     * @param non-empty-string      $payerAccount   Payer account identifier
     * @param non-empty-string|null $payeeAccount   Payee account identifier
     * @param PaymentIntentStatus   $status         Current intent status
     * @param bool                  $scaRequired    Whether SCA is required for this payment
     * @param bool                  $scaCompleted   Whether SCA has been completed
     * @param non-empty-string|null $scaMethod      SCA method used (e.g., "sms", "app", "biometric")
     * @param \DateTimeImmutable    $createdAt      Creation timestamp
     * @param \DateTimeImmutable|null $expiresAt    Expiration timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $payerAccount,
        public readonly ?string $payeeAccount = null,
        public PaymentIntentStatus $status = PaymentIntentStatus::Created,
        public readonly bool $scaRequired = true,
        public bool $scaCompleted = false,
        public readonly ?string $scaMethod = null,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {}

    public function canConfirm(): bool
    {
        if ($this->status !== PaymentIntentStatus::Created) {
            return false;
        }

        if ($this->scaRequired && !$this->scaCompleted) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }
}
