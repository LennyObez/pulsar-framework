<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Internal\Persistence\DbForumProfileRepository;
use Pulsar\Extension\Forum\Profile\ForumProfile;

#[CoversClass(DbForumProfileRepository::class)]
final class DbForumProfileRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbForumProfileRepository $repository;

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
            CREATE TABLE IF NOT EXISTS forum_profiles (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                user_id VARCHAR(36) NOT NULL,
                display_name VARCHAR(100) NOT NULL DEFAULT '',
                bio TEXT DEFAULT NULL,
                avatar_url VARCHAR(500) DEFAULT NULL,
                reputation_score INTEGER NOT NULL DEFAULT 0,
                thread_count INTEGER NOT NULL DEFAULT 0,
                post_count INTEGER NOT NULL DEFAULT 0,
                solution_count INTEGER NOT NULL DEFAULT 0,
                is_banned INTEGER NOT NULL DEFAULT 0,
                banned_at TEXT DEFAULT NULL,
                banned_reason TEXT DEFAULT NULL,
                ban_reason TEXT DEFAULT NULL,
                ban_expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->repository = new DbForumProfileRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $profile = ForumProfile::create(id: 'prof-001', userId: 'user-001');
        $this->repository->save($profile);

        $found = $this->repository->findById('prof-001');

        self::assertNotNull($found);
        self::assertSame('prof-001', $found->id);
        self::assertSame('user-001', $found->userId);
        self::assertSame(0, $found->reputationScore);
        self::assertSame(0, $found->postCount);
        self::assertSame(0, $found->threadCount);
        self::assertFalse($found->isBanned);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByUserReturnsProfile(): void
    {
        $profile = ForumProfile::create(id: 'prof-002', userId: 'user-002');
        $this->repository->save($profile);

        $found = $this->repository->findByUser('user-002');

        self::assertNotNull($found);
        self::assertSame('prof-002', $found->id);
    }

    #[Test]
    public function findByUserReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByUser('nonexistent'));
    }

    #[Test]
    public function saveUpdatesExistingProfile(): void
    {
        $profile = ForumProfile::create(id: 'prof-upd', userId: 'user-upd');
        $this->repository->save($profile);

        $updated = new ForumProfile(
            id: 'prof-upd',
            tenantId: null,
            userId: 'user-upd',
            reputationScore: 50,
            postCount: 10,
            threadCount: 3,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $profile->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
        $this->repository->save($updated);

        $found = $this->repository->findById('prof-upd');
        self::assertNotNull($found);
        self::assertSame(50, $found->reputationScore);
        self::assertSame(10, $found->postCount);
        self::assertSame(3, $found->threadCount);
    }

    #[Test]
    public function incrementReputationAddsToScore(): void
    {
        $profile = ForumProfile::create(id: 'prof-rep', userId: 'user-rep');
        $this->repository->save($profile);

        $this->repository->incrementReputation('user-rep', 15);

        $found = $this->repository->findByUser('user-rep');
        self::assertNotNull($found);
        self::assertSame(15, $found->reputationScore);

        $this->repository->incrementReputation('user-rep', -5);

        $found = $this->repository->findByUser('user-rep');
        self::assertNotNull($found);
        self::assertSame(10, $found->reputationScore);
    }

    #[Test]
    public function incrementPostCountAddsToCount(): void
    {
        $profile = ForumProfile::create(id: 'prof-inc-post', userId: 'user-inc-post');
        $this->repository->save($profile);

        $this->repository->incrementPostCount('user-inc-post', delta: 3);

        $found = $this->repository->findByUser('user-inc-post');
        self::assertNotNull($found);
        self::assertSame(3, $found->postCount);
    }

    #[Test]
    public function incrementThreadCountAddsToCount(): void
    {
        $profile = ForumProfile::create(id: 'prof-inc-thread', userId: 'user-inc-thread');
        $this->repository->save($profile);

        $this->repository->incrementThreadCount('user-inc-thread', delta: 2);

        $found = $this->repository->findByUser('user-inc-thread');
        self::assertNotNull($found);
        self::assertSame(2, $found->threadCount);
    }

    #[Test]
    public function findTopContributorsReturnsPaginated(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $profile = new ForumProfile(
                id: "prof-top-{$i}",
                tenantId: null,
                userId: "user-top-{$i}",
                reputationScore: $i * 10,
                postCount: 0,
                threadCount: 0,
                isBanned: false,
                banReason: null,
                bannedAt: null,
                banExpiresAt: null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            );
            $this->repository->save($profile);
        }

        $result = $this->repository->findTopContributors(page: 1, perPage: 3);

        self::assertSame(5, $result->total);
        self::assertCount(3, $result->items);
        self::assertTrue($result->hasMore);
        self::assertSame(50, $result->items[0]->reputationScore);
        self::assertSame(40, $result->items[1]->reputationScore);
        self::assertSame(30, $result->items[2]->reputationScore);
    }

    #[Test]
    public function deleteRemovesProfile(): void
    {
        $profile = ForumProfile::create(id: 'prof-del', userId: 'user-del');
        $this->repository->save($profile);

        $this->repository->delete($profile);

        self::assertNull($this->repository->findById('prof-del'));
    }

    #[Test]
    public function decrementPostCountFloorsAtZero(): void
    {
        $profile = ForumProfile::create(id: 'prof-floor', userId: 'user-floor');
        $this->repository->save($profile);

        $this->repository->incrementPostCount('user-floor', delta: 3);
        // Decrementing past zero must floor at 0, never go negative.
        $this->repository->incrementPostCount('user-floor', delta: -10);

        $found = $this->repository->findByUser('user-floor');
        self::assertNotNull($found);
        self::assertSame(0, $found->postCount);
    }

    #[Test]
    public function bannedProfileIsPersisted(): void
    {
        $now = new DateTimeImmutable();
        $profile = new ForumProfile(
            id: 'prof-ban',
            tenantId: null,
            userId: 'user-ban',
            reputationScore: 0,
            postCount: 0,
            threadCount: 0,
            isBanned: true,
            banReason: 'Spam',
            bannedAt: $now,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->repository->save($profile);

        $found = $this->repository->findById('prof-ban');
        self::assertNotNull($found);
        self::assertTrue($found->isBanned);
        self::assertSame('Spam', $found->banReason);
    }
}
