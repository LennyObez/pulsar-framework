<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadSubscriptionRepository;
use Pulsar\Extension\Forum\Subscription\ThreadSubscription;

#[CoversClass(DbThreadSubscriptionRepository::class)]
final class DbThreadSubscriptionRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbThreadSubscriptionRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_thread_subscriptions (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                thread_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_subscription_user_thread
                ON forum_thread_subscriptions (user_id, thread_id)
            SQL);

        $this->repository = new DbThreadSubscriptionRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $sub = ThreadSubscription::subscribe(
            id: 'sub-001',
            userId: 'user-001',
            threadId: 'thread-001',
        );
        $this->repository->save($sub);

        $found = $this->repository->findById('sub-001');

        self::assertNotNull($found);
        self::assertSame('sub-001', $found->id);
        self::assertSame('user-001', $found->userId);
        self::assertSame('thread-001', $found->threadId);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByUserAndThread(): void
    {
        $sub = ThreadSubscription::subscribe('sub-ut', 'user-a', 'thread-a');
        $this->repository->save($sub);

        $found = $this->repository->findByUserAndThread('user-a', 'thread-a');

        self::assertNotNull($found);
        self::assertSame('sub-ut', $found->id);
    }

    #[Test]
    public function findByUserAndThreadReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByUserAndThread('user-x', 'thread-x'));
    }

    #[Test]
    public function findByThreadReturnsAllSubscribers(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $sub = ThreadSubscription::subscribe("sub-t-{$i}", "user-{$i}", 'thread-target');
            $this->repository->save($sub);
        }

        $subs = $this->repository->findByThread('thread-target');

        self::assertCount(3, $subs);
    }

    #[Test]
    public function findByUserReturnsAllSubscriptions(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $sub = ThreadSubscription::subscribe("sub-u-{$i}", 'user-target', "thread-{$i}");
            $this->repository->save($sub);
        }

        $subs = $this->repository->findByUser('user-target');

        self::assertCount(3, $subs);
    }

    #[Test]
    public function isSubscribedReturnsTrueWhenSubscribed(): void
    {
        $sub = ThreadSubscription::subscribe('sub-is', 'user-is', 'thread-is');
        $this->repository->save($sub);

        self::assertTrue($this->repository->isSubscribed('user-is', 'thread-is'));
    }

    #[Test]
    public function isSubscribedReturnsFalseWhenNotSubscribed(): void
    {
        self::assertFalse($this->repository->isSubscribed('user-x', 'thread-x'));
    }

    #[Test]
    public function subscribeAndUnsubscribeFlow(): void
    {
        $sub = ThreadSubscription::subscribe('sub-flow', 'user-flow', 'thread-flow');
        $this->repository->save($sub);

        self::assertTrue($this->repository->isSubscribed('user-flow', 'thread-flow'));

        $this->repository->delete($sub);

        self::assertFalse($this->repository->isSubscribed('user-flow', 'thread-flow'));
    }

    #[Test]
    public function saveIsIdempotent(): void
    {
        $sub = ThreadSubscription::subscribe('sub-idem', 'user-idem', 'thread-idem');
        $this->repository->save($sub);
        $this->repository->save($sub);

        self::assertTrue($this->repository->isSubscribed('user-idem', 'thread-idem'));
        self::assertNotNull($this->repository->findById('sub-idem'));
    }

    #[Test]
    public function deleteRemovesSubscription(): void
    {
        $sub = ThreadSubscription::subscribe('sub-del', 'user-del', 'thread-del');
        $this->repository->save($sub);

        $this->repository->delete($sub);

        self::assertNull($this->repository->findById('sub-del'));
    }
}
