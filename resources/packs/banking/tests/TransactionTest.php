<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\Transaction;
use {{namespace}}\Entity\TransactionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transaction::class)]
final class TransactionTest extends TestCase
{
    #[Test]
    public function it_creates_a_pending_transaction(): void
    {
        $transaction = new Transaction(
            id: 'txn_001',
            sourceAccount: 'acc_src',
            targetAccount: 'acc_tgt',
            amountCents: 10000,
            currency: 'USD',
        );

        self::assertSame('txn_001', $transaction->id);
        self::assertSame(10000, $transaction->amountCents);
        self::assertTrue($transaction->isPending());
        self::assertFalse($transaction->isSettled());
    }

    #[Test]
    public function it_formats_amount_correctly(): void
    {
        $transaction = new Transaction(
            id: 'txn_002',
            sourceAccount: 'acc_src',
            targetAccount: 'acc_tgt',
            amountCents: 123456,
            currency: 'EUR',
        );

        self::assertSame('1,234.56', $transaction->amountFormatted());
    }

    // TODO: Add tests for settlement flow, validation, and edge cases
}
