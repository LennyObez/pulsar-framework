<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Subscription\ThreadSubscription;

final class ThreadSubscriptionTest extends TestCase
{
    #[Test]
    public function subscribeSetsAllFields(): void
    {
        $sub = ThreadSubscription::subscribe(
            id: 'sub-1',
            userId: 'user-1',
            threadId: 'thread-1',
        );

        self::assertSame('sub-1', $sub->id);
        self::assertNull($sub->tenantId);
        self::assertSame('user-1', $sub->userId);
        self::assertSame('thread-1', $sub->threadId);
        self::assertEqualsWithDelta(time(), $sub->createdAt->getTimestamp(), 2);
    }

    #[Test]
    public function subscribeWithTenantId(): void
    {
        $sub = ThreadSubscription::subscribe(
            id: 'sub-1',
            userId: 'user-1',
            threadId: 'thread-1',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $sub->tenantId);
    }
}
