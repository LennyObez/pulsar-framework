<?php

declare(strict_types=1);

namespace {{namespace}}\Entity;

use DateTimeImmutable;

/**
 * Financial transaction entity.
 *
 * Represents a monetary transfer between accounts. All transactions
 * are immutable once settled and include a full audit trail.
 */
final class Transaction
{
    /**
     * @param non-empty-string      $id             Unique transaction identifier
     * @param non-empty-string      $sourceAccount  Source account identifier
     * @param non-empty-string      $targetAccount  Target account identifier
     * @param int                   $amountCents    Amount in minor currency units (cents)
     * @param non-empty-string      $currency       ISO 4217 currency code
     * @param TransactionStatus     $status         Current transaction status
     * @param non-empty-string|null $reference      External reference number
     * @param DateTimeImmutable    $createdAt      Creation timestamp
     * @param DateTimeImmutable|null $settledAt    Settlement timestamp
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sourceAccount,
        public readonly string $targetAccount,
        public readonly int $amountCents,
        public readonly string $currency,
        public TransactionStatus $status = TransactionStatus::Pending,
        public readonly ?string $reference = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public readonly ?DateTimeImmutable $settledAt = null,
    ) {}

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }

    public function isSettled(): bool
    {
        return $this->status === TransactionStatus::Settled;
    }

    public function amountFormatted(): string
    {
        return number_format($this->amountCents / 100, 2);
    }
}
