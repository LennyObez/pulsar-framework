<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Feedback\Feedback;
use Pulsar\Extension\Feedback\FeedbackCategory;
use Pulsar\Extension\Feedback\FeedbackStatus;
use Pulsar\Extension\Feedback\Internal\Persistence\DbFeedbackRepository;

#[CoversClass(DbFeedbackRepository::class)]
final class DbFeedbackRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function feedbackRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'fb-001',
            'user_id' => 'user-1',
            'category' => 'bug',
            'description' => 'Login page crashes',
            'context' => '{"page":"/login"}',
            'status' => 'received',
            'admin_response' => null,
            'github_issue_url' => null,
            'created_at' => '2026-01-15T10:00:00+00:00',
            'updated_at' => '2026-01-15T10:00:00+00:00',
        ], $overrides);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveCallsExecuteWithUpsertQuery(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $feedback = new Feedback(
            id: 'fb-001',
            userId: 'user-1',
            category: FeedbackCategory::Bug,
            description: 'Login page crashes',
            context: ['page' => '/login'],
            status: FeedbackStatus::Received,
            adminResponse: null,
            githubIssueUrl: null,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        $repo = new DbFeedbackRepository($db);
        $repo->save($feedback);
    }

    // ── findById ─────────────────────────────────────────────────────

    #[Test]
    public function findByIdReturnsFeedbackWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->feedbackRow()]));

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findById('fb-001');

        self::assertInstanceOf(Feedback::class, $result);
        self::assertSame('fb-001', $result->id);
        self::assertSame('user-1', $result->userId);
        self::assertSame(FeedbackCategory::Bug, $result->category);
        self::assertSame('Login page crashes', $result->description);
        self::assertSame(['page' => '/login'], $result->context);
        self::assertSame(FeedbackStatus::Received, $result->status);
        self::assertNull($result->adminResponse);
        self::assertNull($result->githubIssueUrl);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbFeedbackRepository($db);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findByIdHydratesAllPopulatedFields(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->feedbackRow([
                'category' => 'feature',
                'status' => 'investigating',
                'admin_response' => 'We are looking into this.',
                'github_issue_url' => 'https://github.com/org/repo/issues/42',
            ]),
        ]));

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findById('fb-001');

        self::assertInstanceOf(Feedback::class, $result);
        self::assertSame(FeedbackCategory::Feature, $result->category);
        self::assertSame(FeedbackStatus::Investigating, $result->status);
        self::assertSame('We are looking into this.', $result->adminResponse);
        self::assertSame('https://github.com/org/repo/issues/42', $result->githubIssueUrl);
    }

    // ── findByUser ───────────────────────────────────────────────────

    #[Test]
    public function findByUserReturnsPaginatedResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->feedbackRow()]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findByUser('user-1', 1, 20);

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame(1, $result->currentPage);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findByUserReturnsEmptyWhenNoneExist(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findByUser('user-1', 1, 20);

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function findByUserWithMultiplePagesHasMore(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 45]]),
            Result::fromArrays([$this->feedbackRow()]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findByUser('user-1', 1, 10);

        self::assertSame(45, $result->total);
        self::assertTrue($result->hasMore);
        self::assertSame(5, $result->lastPage);
    }

    // ── findAll ──────────────────────────────────────────────────────

    #[Test]
    public function findAllReturnsPaginatedResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 2]]),
            Result::fromArrays([
                $this->feedbackRow(['id' => 'fb-001']),
                $this->feedbackRow(['id' => 'fb-002']),
            ]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findAll(1, 20);

        self::assertSame(2, $result->total);
        self::assertCount(2, $result->items);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findAllWithCategoryFilter(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->feedbackRow(['category' => 'bug'])]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findAll(1, 20, category: FeedbackCategory::Bug);

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
    }

    #[Test]
    public function findAllWithStatusFilter(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findAll(1, 20, status: FeedbackStatus::Resolved);

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function findAllWithBothFilters(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->feedbackRow()]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findAll(
            1,
            20,
            category: FeedbackCategory::Bug,
            status: FeedbackStatus::Received,
        );

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
    }

    #[Test]
    public function findAllClampsPerPageToMaximumOfOneHundred(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbFeedbackRepository($db);
        $result = $repo->findAll(1, 500);

        self::assertSame(100, $result->perPage);
    }

    // ── countByUserToday ─────────────────────────────────────────────

    #[Test]
    public function countByUserTodayReturnsCount(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([['total' => 3]]));

        $repo = new DbFeedbackRepository($db);

        self::assertSame(3, $repo->countByUserToday('user-1'));
    }

    #[Test]
    public function countByUserTodayReturnsZeroWhenNoRows(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbFeedbackRepository($db);

        self::assertSame(0, $repo->countByUserToday('user-1'));
    }
}
