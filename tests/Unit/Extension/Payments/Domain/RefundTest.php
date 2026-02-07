<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;

#[CoversClass(Refund::class)]
final class RefundTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $now = new DateTimeImmutable();
        $refund = new Refund(
            id: 'rf_test',
            chargeId: 'ch_test',
            amount: Money::of(500, Currency::USD),
            status: RefundStatus::Succeeded,
            provider: 'test',
            createdAt: $now,
            failureReason: null,
            metadata: ['reason' => 'customer_request'],
        );

        self::assertSame('rf_test', $refund->id);
        self::assertSame('ch_test', $refund->chargeId);
        self::assertSame(500, $refund->amount->amount);
        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame('test', $refund->provider);
        self::assertSame($now, $refund->createdAt);
        self::assertNull($refund->failureReason);
        self::assertSame(['reason' => 'customer_request'], $refund->metadata);
    }

    #[Test]
    public function constructsWithFailureReason(): void
    {
        $refund = new Refund(
            id: 'rf_fail',
            chargeId: 'ch_test',
            amount: Money::of(1000, Currency::EUR),
            status: RefundStatus::Failed,
            provider: 'test',
            createdAt: new DateTimeImmutable(),
            failureReason: 'insufficient_balance',
        );

        self::assertSame('insufficient_balance', $refund->failureReason);
        self::assertSame(RefundStatus::Failed, $refund->status);
    }
}
