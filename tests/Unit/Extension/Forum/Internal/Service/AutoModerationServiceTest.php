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
use Pulsar\Extension\Forum\Internal\Service\AutoModerationService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;

#[CoversClass(AutoModerationService::class)]
final class AutoModerationServiceTest extends TestCase
{
    private PostRepositoryInterface&Stub $posts;
    private ThreadReportRepositoryInterface&Stub $threadReports;
    private PostReportRepositoryInterface&Stub $postReports;
    private AutoModerationService $service;

    protected function setUp(): void
    {
        $this->posts = $this->createStub(PostRepositoryInterface::class);
        $this->threadReports = $this->createStub(ThreadReportRepositoryInterface::class);
        $this->postReports = $this->createStub(PostReportRepositoryInterface::class);

        $this->service = new AutoModerationService(
            $this->posts,
            $this->threadReports,
            $this->postReports,
        );
    }

    // --- shouldQueueForReview ---

    #[Test]
    public function newUserPostsEnterModerationQueue(): void
    {
        $author = self::profileWithReputation(5);

        self::assertTrue($this->service->shouldQueueForReview($author));
    }

    #[Test]
    public function userAtReviewThresholdDoesNotQueue(): void
    {
        $author = self::profileWithReputation(10);

        self::assertFalse($this->service->shouldQueueForReview($author));
    }

    #[Test]
    public function trustedUserBypassesQueue(): void
    {
        $author = self::profileWithReputation(100);

        self::assertFalse($this->service->shouldQueueForReview($author));
    }

    #[Test]
    public function zeroReputationQueuesForReview(): void
    {
        $author = self::profileWithReputation(0);

        self::assertTrue($this->service->shouldQueueForReview($author));
    }

    // --- shouldAutoHide ---

    #[Test]
    public function threadAutoHidesAtFlagThreshold(): void
    {
        $this->threadReports->method('countPendingForThread')->willReturn(3);

        self::assertTrue($this->service->shouldAutoHide('thread', 'thread-001'));
    }

    #[Test]
    public function postAutoHidesAtFlagThreshold(): void
    {
        $this->postReports->method('countPendingForPost')->willReturn(3);

        self::assertTrue($this->service->shouldAutoHide('post', 'post-001'));
    }

    #[Test]
    public function contentBelowFlagThresholdNotHidden(): void
    {
        $this->threadReports->method('countPendingForThread')->willReturn(2);

        self::assertFalse($this->service->shouldAutoHide('thread', 'thread-001'));
    }

    #[Test]
    public function contentAboveFlagThresholdAutoHides(): void
    {
        $this->postReports->method('countPendingForPost')->willReturn(10);

        self::assertTrue($this->service->shouldAutoHide('post', 'post-001'));
    }

    #[Test]
    public function unknownTargetTypeNeverAutoHides(): void
    {
        self::assertFalse($this->service->shouldAutoHide('comment', 'comment-001'));
    }

    // --- isUrlLimitExceeded ---

    #[Test]
    public function lowReputationUserExceedingUrlLimitIsDetected(): void
    {
        $author = self::profileWithReputation(5);
        $body = 'Check http://spam1.com and http://spam2.com and http://spam3.com';

        self::assertTrue($this->service->isUrlLimitExceeded($author, $body));
    }

    #[Test]
    public function lowReputationUserWithinUrlLimitPasses(): void
    {
        $author = self::profileWithReputation(5);
        $body = 'See http://example.com and http://docs.example.com';

        self::assertFalse($this->service->isUrlLimitExceeded($author, $body));
    }

    #[Test]
    public function highReputationUserIgnoresUrlLimit(): void
    {
        $author = self::profileWithReputation(50);
        $body = 'Links: http://a.com http://b.com http://c.com http://d.com http://e.com';

        self::assertFalse($this->service->isUrlLimitExceeded($author, $body));
    }

    #[Test]
    public function bodyWithNoUrlsPassesUrlCheck(): void
    {
        $author = self::profileWithReputation(0);
        $body = 'This is a normal post without any links.';

        self::assertFalse($this->service->isUrlLimitExceeded($author, $body));
    }

    // --- isDuplicate ---

    #[Test]
    public function identicalRecentPostDetectedAsDuplicate(): void
    {
        $body = 'This is a duplicate post content that should be flagged.';
        $since = new DateTimeImmutable('-1 hour');
        $post = self::makePost('post-001', 'user-001', $body, new DateTimeImmutable());

        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 10),
        );

        self::assertTrue($this->service->isDuplicate('user-001', $body, $since));
    }

    #[Test]
    public function differentPostNotDetectedAsDuplicate(): void
    {
        $since = new DateTimeImmutable('-1 hour');
        $post = self::makePost('post-001', 'user-001', 'A completely different message about another topic', new DateTimeImmutable());

        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 10),
        );

        self::assertFalse($this->service->isDuplicate('user-001', 'New unique content that is not similar at all', $since));
    }

    #[Test]
    public function oldPostNotConsideredDuplicate(): void
    {
        $since = new DateTimeImmutable('-1 hour');
        $body = 'Duplicate content that was posted long ago';
        $post = self::makePost('post-001', 'user-001', $body, new DateTimeImmutable('-2 hours'));

        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 10),
        );

        self::assertFalse($this->service->isDuplicate('user-001', $body, $since));
    }

    #[Test]
    public function noRecentPostsMeansNoDuplicate(): void
    {
        $since = new DateTimeImmutable('-1 hour');

        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 10),
        );

        self::assertFalse($this->service->isDuplicate('user-001', 'Any content', $since));
    }

    // --- isPostingTooFast ---

    #[Test]
    public function lowReputationUserPostingTooFastIsDetected(): void
    {
        $author = self::profileWithReputation(5);
        $lastPostAt = new DateTimeImmutable('-1 minute');

        self::assertTrue($this->service->isPostingTooFast($author, $lastPostAt));
    }

    #[Test]
    public function lowReputationUserAfterCooldownPasses(): void
    {
        $author = self::profileWithReputation(5);
        $lastPostAt = new DateTimeImmutable('-10 minutes');

        self::assertFalse($this->service->isPostingTooFast($author, $lastPostAt));
    }

    #[Test]
    public function highReputationUserNotRateLimited(): void
    {
        $author = self::profileWithReputation(10);
        $lastPostAt = new DateTimeImmutable('-1 second');

        self::assertFalse($this->service->isPostingTooFast($author, $lastPostAt));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideReviewThresholds(): iterable
    {
        yield 'score 0 queues' => [0, true];
        yield 'score 9 queues' => [9, true];
        yield 'score 10 does not queue' => [10, false];
        yield 'score 11 does not queue' => [11, false];
    }

    #[Test]
    #[DataProvider('provideReviewThresholds')]
    public function reviewQueueThresholdsAreCorrect(int $score, bool $shouldQueue): void
    {
        $author = self::profileWithReputation($score);

        self::assertSame($shouldQueue, $this->service->shouldQueueForReview($author));
    }

    private static function profileWithReputation(int $score): ForumProfile
    {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-001',
            tenantId: null,
            userId: 'user-001',
            reputationScore: $score,
            postCount: 5,
            threadCount: 1,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private static function makePost(
        string $id,
        string $authorId,
        string $body,
        DateTimeImmutable $createdAt,
    ): Post {
        return new Post(
            id: $id,
            tenantId: null,
            threadId: 'thread-001',
            parentId: null,
            authorId: $authorId,
            body: $body,
            bodyHtml: "<p>{$body}</p>",
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'hash',
            userAgentHash: 'hash',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            deletedAt: null,
            version: 1,
        );
    }
}
