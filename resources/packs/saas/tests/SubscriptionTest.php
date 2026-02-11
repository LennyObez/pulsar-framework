<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\Subscription;
use {{namespace}}\Entity\SubscriptionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Subscription::class)]
final class SubscriptionTest extends TestCase
{
    #[Test]
    public function it_creates_an_active_subscription(): void
    {
        $subscription = new Subscription(
            id: 'sub_001',
            tenantId: 'ten_001',
            planId: 'plan_professional',
        );

        self::assertSame('sub_001', $subscription->id);
        self::assertTrue($subscription->isActive());
        self::assertFalse($subscription->isCancelled());
    }

    #[Test]
    public function it_tracks_cancellation_status(): void
    {
        $subscription = new Subscription(
            id: 'sub_002',
            tenantId: 'ten_001',
            planId: 'plan_professional',
            status: SubscriptionStatus::Cancelled,
            cancelledAt: new \DateTimeImmutable(),
        );

        self::assertTrue($subscription->isCancelled());
        self::assertFalse($subscription->isActive());
    }

    // TODO: Add tests for grace period, plan changes, and billing cycle
}
