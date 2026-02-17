<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;
use ValueError;

#[CoversClass(SubscriptionStatus::class)]
final class SubscriptionStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{SubscriptionStatus, string}>
     */
    public static function statusValueProvider(): iterable
    {
        yield 'active' => [SubscriptionStatus::Active, 'active'];
        yield 'expired' => [SubscriptionStatus::Expired, 'expired'];
        yield 'grace_period' => [SubscriptionStatus::GracePeriod, 'grace_period'];
        yield 'cancelled' => [SubscriptionStatus::Cancelled, 'cancelled'];
        yield 'billing_retry' => [SubscriptionStatus::BillingRetry, 'billing_retry'];
        yield 'revoked' => [SubscriptionStatus::Revoked, 'revoked'];
    }

    #[Test]
    #[DataProvider('statusValueProvider')]
    public function backedValueMatchesExpected(SubscriptionStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    #[Test]
    public function allSixCasesExist(): void
    {
        self::assertCount(6, SubscriptionStatus::cases());
    }

    #[Test]
    public function fromStringReturnsCorrectCase(): void
    {
        self::assertSame(SubscriptionStatus::GracePeriod, SubscriptionStatus::from('grace_period'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(SubscriptionStatus::tryFrom('nonexistent'));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        SubscriptionStatus::from('invalid_status');
    }
}
