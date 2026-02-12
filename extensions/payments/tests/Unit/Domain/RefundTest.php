<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;

final class RefundTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            chargeId: 'ch_1',
            amount: Money::of(1000, Currency::USD),
            status: RefundStatus::Succeeded,
            provider: 'stripe',
            createdAt: new DateTimeImmutable('2024-06-15'),
            failureReason: null,
            metadata: ['reason_code' => 'duplicate'],
        );

        self::assertSame('ref_1', $refund->id);
        self::assertSame('ch_1', $refund->chargeId);
        self::assertSame(1000, $refund->amount->amount);
        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame('stripe', $refund->provider);
        self::assertNull($refund->failureReason);
        self::assertSame(['reason_code' => 'duplicate'], $refund->metadata);
    }

    #[Test]
    public function failedRefundHasReason(): void
    {
        $refund = new Refund(
            id: 'ref_2',
            chargeId: 'ch_2',
            amount: Money::of(500, Currency::EUR),
            status: RefundStatus::Failed,
            provider: 'stripe',
            createdAt: new DateTimeImmutable(),
            failureReason: 'charge_already_refunded',
        );

        self::assertSame(RefundStatus::Failed, $refund->status);
        self::assertSame('charge_already_refunded', $refund->failureReason);
    }
}
