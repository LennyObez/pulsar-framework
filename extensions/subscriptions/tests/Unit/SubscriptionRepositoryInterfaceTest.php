<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;

use function bin2hex;
use function random_bytes;

#[CoversNothing]
final class SubscriptionRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnSubscriptionForFindByUser(): void
    {
        $subscription = Subscription::create(
            userId: 'user-1',
            store: Store::Google,
            productId: 'com.app.pro',
            plan: 'pro_monthly',
            purchaseTokenHash: bin2hex(random_bytes(16)),
            originalTransactionId: 'GPA.0001',
        );

        $stub = $this->createStub(SubscriptionRepositoryInterface::class);
        $stub->method('findByUser')->willReturn($subscription);

        self::assertSame($subscription, $stub->findByUser('user-1'));
    }

    #[Test]
    public function stubCanReturnNullForFindByUser(): void
    {
        $stub = $this->createStub(SubscriptionRepositoryInterface::class);
        $stub->method('findByUser')->willReturn(null);

        self::assertNull($stub->findByUser('nonexistent'));
    }

    #[Test]
    public function stubCanReturnSubscriptionForFindByPurchaseTokenHash(): void
    {
        $subscription = Subscription::create(
            userId: 'user-2',
            store: Store::Apple,
            productId: 'com.app.gold',
            plan: 'gold_yearly',
            purchaseTokenHash: 'hash-test',
            originalTransactionId: 'APPLE.0002',
        );

        $stub = $this->createStub(SubscriptionRepositoryInterface::class);
        $stub->method('findByPurchaseTokenHash')->willReturn($subscription);

        $result = $stub->findByPurchaseTokenHash('hash-test');
        self::assertSame(Store::Apple, $result->store);
    }

    #[Test]
    public function stubCanReturnSubscriptionForFindByOriginalTransactionId(): void
    {
        $subscription = Subscription::create(
            userId: 'user-3',
            store: Store::Google,
            productId: 'com.app.basic',
            plan: 'basic',
            purchaseTokenHash: 'hash-basic',
            originalTransactionId: 'GPA.ORIG',
        );

        $stub = $this->createStub(SubscriptionRepositoryInterface::class);
        $stub->method('findByOriginalTransactionId')->willReturn($subscription);

        $result = $stub->findByOriginalTransactionId('GPA.ORIG');
        self::assertSame(SubscriptionStatus::Active, $result->status);
    }
}
