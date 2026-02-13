<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use ValueError;

final class SubscriptionStatusTest extends TestCase
{
    #[Test]
    public function caseCount(): void
    {
        self::assertCount(6, SubscriptionStatus::cases());
    }

    #[Test]
    public function allValuesAreUniqueStrings(): void
    {
        $values = array_map(
            static fn(SubscriptionStatus $s): string => $s->value,
            SubscriptionStatus::cases(),
        );

        self::assertSame($values, array_unique($values));

        foreach ($values as $value) {
            self::assertIsString($value);
            self::assertNotEmpty($value);
        }
    }

    #[Test]
    public function expectedValues(): void
    {
        self::assertSame('active', SubscriptionStatus::Active->value);
        self::assertSame('expired', SubscriptionStatus::Expired->value);
        self::assertSame('grace_period', SubscriptionStatus::GracePeriod->value);
        self::assertSame('cancelled', SubscriptionStatus::Cancelled->value);
        self::assertSame('billing_retry', SubscriptionStatus::BillingRetry->value);
        self::assertSame('revoked', SubscriptionStatus::Revoked->value);
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(SubscriptionStatus::Active, SubscriptionStatus::from('active'));
        self::assertSame(SubscriptionStatus::GracePeriod, SubscriptionStatus::from('grace_period'));
    }

    #[Test]
    public function tryFromInvalidReturnsNull(): void
    {
        self::assertNull(SubscriptionStatus::tryFrom('pending'));
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        SubscriptionStatus::from('pending');
    }
}
