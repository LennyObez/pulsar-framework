<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

/**
 * Bank account entity.
 *
 * Represents a financial account with balance tracking and status management.
 * Account numbers are encrypted at rest per the encryption configuration.
 */
final class Account
{
    /**
     * @param non-empty-string      $id            Unique account identifier
     * @param non-empty-string      $accountNumber Encrypted account number
     * @param non-empty-string      $holderName    Account holder name
     * @param non-empty-string      $currency      ISO 4217 currency code
     * @param int                   $balanceCents  Current balance in minor currency units
     * @param AccountStatus         $status        Current account status
     * @param \DateTimeImmutable    $openedAt      Account opening timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $accountNumber,
        public readonly string $holderName,
        public readonly string $currency,
        public int $balanceCents = 0,
        public AccountStatus $status = AccountStatus::Active,
        public readonly \DateTimeImmutable $openedAt = new \DateTimeImmutable(),
    ) {}

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function balanceFormatted(): string
    {
        return number_format($this->balanceCents / 100, 2, '.', ',');
    }

    public function hasSufficientFunds(int $amountCents): bool
    {
        return $this->balanceCents >= $amountCents;
    }
}
