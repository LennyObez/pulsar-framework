<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

#[CoversClass(Charge::class)]
final class ChargeTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $now = new DateTimeImmutable();
        $charge = new Charge(
            id: 'ch_test',
            intentId: 'pi_test',
            amount: Money::of(2000, Currency::USD),
            status: ChargeStatus::Succeeded,
            provider: 'test',
            createdAt: $now,
            failureReason: null,
            metadata: ['key' => 'value'],
        );

        self::assertSame('ch_test', $charge->id);
        self::assertSame('pi_test', $charge->intentId);
        self::assertSame(2000, $charge->amount->amount);
        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame('test', $charge->provider);
        self::assertSame($now, $charge->createdAt);
        self::assertNull($charge->failureReason);
        self::assertSame(['key' => 'value'], $charge->metadata);
    }

    #[Test]
    public function constructsWithFailureReason(): void
    {
        $charge = new Charge(
            id: 'ch_fail',
            intentId: 'pi_test',
            amount: Money::of(1000, Currency::EUR),
            status: ChargeStatus::Failed,
            provider: 'test',
            createdAt: new DateTimeImmutable(),
            failureReason: 'insufficient_funds',
        );

        self::assertSame('insufficient_funds', $charge->failureReason);
        self::assertSame(ChargeStatus::Failed, $charge->status);
    }

    #[Test]
    public function defaultMetadataIsEmpty(): void
    {
        $charge = new Charge(
            id: 'ch_test',
            intentId: 'pi_test',
            amount: Money::of(1000, Currency::USD),
            status: ChargeStatus::Succeeded,
            provider: 'test',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame([], $charge->metadata);
    }
}
