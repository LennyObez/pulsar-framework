<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Internal\Persistence\DbUserBadgeRepository;

#[CoversClass(DbUserBadgeRepository::class)]
final class DbUserBadgeRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbUserBadgeRepository $repository;

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
            CREATE TABLE IF NOT EXISTS forum_user_badges (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                user_id VARCHAR(36) NOT NULL,
                badge VARCHAR(50) NOT NULL,
                awarded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        $this->connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_user_badge ON forum_user_badges (user_id, badge)
            SQL);

        $this->repository = new DbUserBadgeRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $badge = UserBadge::award(
            id: 'ub-001',
            userId: 'user-001',
            badge: Badge::FirstPost,
        );
        $this->repository->save($badge);

        $found = $this->repository->findById('ub-001');

        self::assertNotNull($found);
        self::assertSame('ub-001', $found->id);
        self::assertSame('user-001', $found->userId);
        self::assertSame(Badge::FirstPost, $found->badge);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findByUserReturnsAllBadges(): void
    {
        $badge1 = UserBadge::award('ub-a', 'user-badges', Badge::FirstPost);
        $badge2 = UserBadge::award('ub-b', 'user-badges', Badge::Helpful);
        $badge3 = UserBadge::award('ub-c', 'user-badges', Badge::Solver);

        $this->repository->save($badge1);
        $this->repository->save($badge2);
        $this->repository->save($badge3);

        $badges = $this->repository->findByUser('user-badges');

        self::assertCount(3, $badges);
    }

    #[Test]
    public function findByUserReturnsEmptyArrayWhenNoBadges(): void
    {
        self::assertSame([], $this->repository->findByUser('user-no-badges'));
    }

    #[Test]
    public function hasBadgeReturnsTrueWhenBadgeExists(): void
    {
        $badge = UserBadge::award('ub-has', 'user-has', Badge::BugHunter);
        $this->repository->save($badge);

        self::assertTrue($this->repository->hasBadge('user-has', Badge::BugHunter));
    }

    #[Test]
    public function hasBadgeReturnsFalseWhenBadgeDoesNotExist(): void
    {
        self::assertFalse($this->repository->hasBadge('user-no', Badge::Contributor));
    }

    #[Test]
    public function hasBadgeReturnsFalseForDifferentBadge(): void
    {
        $badge = UserBadge::award('ub-diff', 'user-diff', Badge::FirstPost);
        $this->repository->save($badge);

        self::assertFalse($this->repository->hasBadge('user-diff', Badge::Solver));
    }

    #[Test]
    public function deleteRemovesBadge(): void
    {
        $badge = UserBadge::award('ub-del', 'user-del', Badge::PopularThread);
        $this->repository->save($badge);

        $this->repository->delete($badge);

        self::assertNull($this->repository->findById('ub-del'));
        self::assertFalse($this->repository->hasBadge('user-del', Badge::PopularThread));
    }

    #[Test]
    public function awardMultipleDifferentBadgesToSameUser(): void
    {
        foreach ([Badge::FirstPost, Badge::FirstAnswer, Badge::Helpful, Badge::Solver] as $i => $badge) {
            $ub = UserBadge::award("ub-multi-{$i}", 'user-multi', $badge);
            $this->repository->save($ub);
        }

        $badges = $this->repository->findByUser('user-multi');
        self::assertCount(4, $badges);

        self::assertTrue($this->repository->hasBadge('user-multi', Badge::FirstPost));
        self::assertTrue($this->repository->hasBadge('user-multi', Badge::Solver));
        self::assertFalse($this->repository->hasBadge('user-multi', Badge::BugHunter));
    }
}
