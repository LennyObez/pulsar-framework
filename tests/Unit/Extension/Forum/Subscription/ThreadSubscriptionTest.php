<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Subscription;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Subscription\ThreadSubscription;

#[CoversClass(ThreadSubscription::class)]
final class ThreadSubscriptionTest extends TestCase
{
    #[Test]
    public function subscribeCreatesInstanceWithCorrectProperties(): void
    {
        $before = new DateTimeImmutable();
        $sub = ThreadSubscription::subscribe(
            id: 'sub-1',
            userId: 'user-1',
            threadId: 'thread-1',
        );
        $after = new DateTimeImmutable();

        self::assertSame('sub-1', $sub->id);
        self::assertNull($sub->tenantId);
        self::assertSame('user-1', $sub->userId);
        self::assertSame('thread-1', $sub->threadId);
        self::assertGreaterThanOrEqual($before, $sub->createdAt);
        self::assertLessThanOrEqual($after, $sub->createdAt);
    }

    #[Test]
    public function subscribeWithTenantIdSetsIt(): void
    {
        $sub = ThreadSubscription::subscribe(
            id: 'sub-2',
            userId: 'user-2',
            threadId: 'thread-2',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $sub->tenantId);
    }

    #[Test]
    public function subscribeWithoutTenantIdDefaultsToNull(): void
    {
        $sub = ThreadSubscription::subscribe(
            id: 'sub-3',
            userId: 'user-3',
            threadId: 'thread-3',
        );

        self::assertNull($sub->tenantId);
    }

    #[Test]
    public function constructorAllowsExplicitCreatedAt(): void
    {
        $timestamp = new DateTimeImmutable('2024-01-15 10:30:00');
        $sub = new ThreadSubscription(
            id: 'sub-4',
            tenantId: null,
            userId: 'user-4',
            threadId: 'thread-4',
            createdAt: $timestamp,
        );

        self::assertSame($timestamp, $sub->createdAt);
    }

    #[Test]
    public function subscribeReturnsDifferentInstancesForDifferentUsers(): void
    {
        $sub1 = ThreadSubscription::subscribe('s1', 'user-a', 'thread-1');
        $sub2 = ThreadSubscription::subscribe('s2', 'user-b', 'thread-1');

        self::assertNotSame($sub1->userId, $sub2->userId);
        self::assertSame($sub1->threadId, $sub2->threadId);
    }
}
