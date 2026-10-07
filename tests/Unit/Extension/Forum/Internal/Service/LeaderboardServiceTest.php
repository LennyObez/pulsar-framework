<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Internal\Service\LeaderboardService;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;

#[CoversClass(LeaderboardService::class)]
final class LeaderboardServiceTest extends TestCase
{
    private ForumProfileRepositoryInterface&Stub $profiles;
    private ConnectionInterface&Stub $connection;
    private LeaderboardService $service;

    protected function setUp(): void
    {
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::SQLite);

        $this->service = new LeaderboardService($this->profiles, $this->connection);
    }

    #[Test]
    public function allTimeLeaderboardReturnsUsersOrderedByReputation(): void
    {
        $profile1 = self::profileWithReputation('user-001', 1000);
        $profile2 = self::profileWithReputation('user-002', 500);
        $profile3 = self::profileWithReputation('user-003', 100);

        $this->profiles->method('findTopContributors')->willReturn(
            new PaginationResult(
                items: [$profile1, $profile2, $profile3],
                total: 3,
                hasMore: false,
                perPage: 25,
            ),
        );

        $result = $this->service->getTopUsers('all', 25);

        self::assertCount(3, $result);
        self::assertSame(1, $result[0]['rank']);
        self::assertSame('user-001', $result[0]['profile']->userId);
        self::assertSame(2, $result[1]['rank']);
        self::assertSame('user-002', $result[1]['profile']->userId);
        self::assertSame(3, $result[2]['rank']);
        self::assertSame('user-003', $result[2]['profile']->userId);
    }

    #[Test]
    public function allTimeLeaderboardReturnsEmptyForNoUsers(): void
    {
        $this->profiles->method('findTopContributors')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 25),
        );

        $result = $this->service->getTopUsers('all', 25);

        self::assertSame([], $result);
    }

    #[Test]
    public function weeklyLeaderboardQueriesRecentActivity(): void
    {
        $row = new Row([
            'id' => 'profile-001',
            'tenant_id' => null,
            'user_id' => 'user-001',
            'reputation_score' => 100,
            'post_count' => 10,
            'thread_count' => 2,
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
            'ban_expires_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
            'recent_activity' => 5,
        ]);

        $this->connection->method('query')->willReturn(new Result([$row]));

        $result = $this->service->getTopUsers('week', 25);

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['rank']);
        self::assertSame('user-001', $result[0]['profile']->userId);
    }

    #[Test]
    public function monthlyLeaderboardQueriesRecentActivity(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $result = $this->service->getTopUsers('month', 25);

        self::assertSame([], $result);
    }

    #[Test]
    public function limitIsClampedToRange(): void
    {
        $this->profiles->method('findTopContributors')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100),
        );

        // limit > 100 should be clamped to 100
        $result = $this->service->getTopUsers('all', 200);
        self::assertSame([], $result);

        // limit < 1 should be clamped to 1
        $result = $this->service->getTopUsers('all', 0);
        self::assertSame([], $result);
    }

    #[Test]
    public function defaultPeriodUsesAllTime(): void
    {
        $this->profiles->method('findTopContributors')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 25),
        );

        $result = $this->service->getTopUsers();

        self::assertSame([], $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidPeriods(): iterable
    {
        yield 'all time' => ['all'];
        yield 'weekly' => ['week'];
        yield 'monthly' => ['month'];
    }

    #[Test]
    #[DataProvider('provideValidPeriods')]
    public function acceptsAllValidPeriods(string $period): void
    {
        if ($period === 'all') {
            $this->profiles->method('findTopContributors')->willReturn(
                new PaginationResult(items: [], total: 0, hasMore: false, perPage: 25),
            );
        } else {
            $this->connection->method('query')->willReturn(new Result([]));
        }

        $result = $this->service->getTopUsers($period, 10);

        self::assertSame([], $result);
    }

    private static function profileWithReputation(string $userId, int $score): ForumProfile
    {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-' . $userId,
            tenantId: null,
            userId: $userId,
            reputationScore: $score,
            postCount: 10,
            threadCount: 2,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
