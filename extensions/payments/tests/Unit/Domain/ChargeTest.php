<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

final class ChargeTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $charge = new Charge(
            id: 'ch_1',
            intentId: 'pi_1',
            amount: Money::of(2500, Currency::EUR),
            status: ChargeStatus::Succeeded,
            provider: 'stripe',
            createdAt: new DateTimeImmutable('2024-06-15'),
            failureReason: null,
            metadata: ['order_id' => 'ord_42'],
        );

        self::assertSame('ch_1', $charge->id);
        self::assertSame('pi_1', $charge->intentId);
        self::assertSame(2500, $charge->amount->amount);
        self::assertSame(Currency::EUR, $charge->amount->currency);
        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame('stripe', $charge->provider);
        self::assertNull($charge->failureReason);
        self::assertSame(['order_id' => 'ord_42'], $charge->metadata);
    }

    #[Test]
    public function failureReasonCanBeSet(): void
    {
        $charge = new Charge(
            id: 'ch_2',
            intentId: 'pi_2',
            amount: Money::of(1000, Currency::USD),
            status: ChargeStatus::Failed,
            provider: 'stripe',
            createdAt: new DateTimeImmutable(),
            failureReason: 'insufficient_funds',
        );

        self::assertSame('insufficient_funds', $charge->failureReason);
        self::assertSame(ChargeStatus::Failed, $charge->status);
    }
}
