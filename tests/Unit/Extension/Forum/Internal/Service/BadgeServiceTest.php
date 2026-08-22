<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Config\BadgeConfig;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Event\BadgeAwarded;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Internal\Service\BadgeService;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;

#[CoversClass(BadgeService::class)]
final class BadgeServiceTest extends TestCase
{
    private UserBadgeRepositoryInterface&Stub $userBadges;
    private ForumProfileRepositoryInterface&Stub $profiles;
    private PostRepositoryInterface&Stub $posts;
    private ThreadRepositoryInterface&Stub $threads;
    private EventDispatcherInterface&Stub $events;
    private ForumConfig $config;

    protected function setUp(): void
    {
        $this->userBadges = $this->createStub(UserBadgeRepositoryInterface::class);
        $this->profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $this->posts = $this->createStub(PostRepositoryInterface::class);
        $this->threads = $this->createStub(ThreadRepositoryInterface::class);
        $this->events = $this->createStub(EventDispatcherInterface::class);
        $this->config = new ForumConfig();
    }

    private function makeService(
        ?UserBadgeRepositoryInterface $userBadges = null,
        ?EventDispatcherInterface $events = null,
        ?ForumConfig $config = null,
    ): BadgeService {
        return new BadgeService(
            userBadges: $userBadges ?? $this->userBadges,
            profiles: $this->profiles,
            posts: $this->posts,
            threads: $this->threads,
            events: $events ?? $this->events,
            config: $config ?? $this->config,
        );
    }

    private function makeProfile(int $threadCount = 0, int $postCount = 0, int $reputation = 0): ForumProfile
    {
        return new ForumProfile(
            id: 'p-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: $reputation,
            postCount: $postCount,
            threadCount: $threadCount,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    private function makePost(bool $isSolution = false, int $voteScore = 0): Post
    {
        return new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'Body',
            bodyHtml: '<p>Body</p>',
            isSolution: $isSolution,
            voteScore: $voteScore,
            editCount: 0,
            editedBy: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
    }

    private function makeThread(int $viewCount = 0): Thread
    {
        return new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Thread',
            slug: 'thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 0,
            viewCount: $viewCount,
            voteScore: 0,
            lastActivityAt: new DateTimeImmutable(),
            ipHash: 'ip',
            userAgentHash: 'ua',
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
        );
    }

    #[Test]
    public function evaluateReturnsFalseWhenBadgesDisabled(): void
    {
        $config = new ForumConfig(badges: new BadgeConfig(enabled: false));
        $service = $this->makeService(config: $config);

        self::assertFalse($service->evaluate('user-1', Badge::FirstPost));
    }

    #[Test]
    public function evaluateReturnsFalseWhenProfileNotFound(): void
    {
        $this->profiles->method('findByUser')->willReturn(null);

        $service = $this->makeService();

        self::assertFalse($service->evaluate('user-1', Badge::FirstPost));
    }

    #[Test]
    public function evaluateFirstPostTrueWhenUserHasPosts(): void
    {
        $profile = $this->makeProfile(postCount: 1);
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::FirstPost));
    }

    #[Test]
    public function evaluateFirstPostTrueWhenUserHasThreads(): void
    {
        $profile = $this->makeProfile(threadCount: 1);
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::FirstPost));
    }

    #[Test]
    public function evaluateFirstPostFalseWhenZeroActivity(): void
    {
        $profile = $this->makeProfile();
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertFalse($service->evaluate('user-1', Badge::FirstPost));
    }

    #[Test]
    public function evaluateFirstAnswerTrueWhenHasAcceptedSolution(): void
    {
        $profile = $this->makeProfile(postCount: 5);
        $this->profiles->method('findByUser')->willReturn($profile);

        $post = $this->makePost(isSolution: true);
        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 100),
        );

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::FirstAnswer));
    }

    #[Test]
    public function evaluateFirstAnswerFalseWhenNoSolutions(): void
    {
        $profile = $this->makeProfile(postCount: 5);
        $this->profiles->method('findByUser')->willReturn($profile);

        $post = $this->makePost(isSolution: false);
        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 100),
        );

        $service = $this->makeService();

        self::assertFalse($service->evaluate('user-1', Badge::FirstAnswer));
    }

    #[Test]
    public function evaluateHelpfulTrueWhenHighVoteScore(): void
    {
        $profile = $this->makeProfile(postCount: 1);
        $this->profiles->method('findByUser')->willReturn($profile);

        $post = $this->makePost(voteScore: 5);
        $this->posts->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 100),
        );

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::Helpful));
    }

    #[Test]
    public function evaluatePopularThreadTrueWhenHighViewCount(): void
    {
        $profile = $this->makeProfile(threadCount: 1);
        $this->profiles->method('findByUser')->willReturn($profile);

        $thread = $this->makeThread(viewCount: 100);
        $this->threads->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$thread], total: 1, hasMore: false, perPage: 100),
        );

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::PopularThread));
    }

    #[Test]
    public function evaluatePopularThreadFalseWhenLowViewCount(): void
    {
        $profile = $this->makeProfile(threadCount: 1);
        $this->profiles->method('findByUser')->willReturn($profile);

        $thread = $this->makeThread(viewCount: 50);
        $this->threads->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$thread], total: 1, hasMore: false, perPage: 100),
        );

        $service = $this->makeService();

        self::assertFalse($service->evaluate('user-1', Badge::PopularThread));
    }

    #[Test]
    public function evaluateContributorTrueWhenReputationSufficient(): void
    {
        $profile = $this->makeProfile(reputation: 10);
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::Contributor));
    }

    #[Test]
    public function evaluateContributorFalseWhenNewcomer(): void
    {
        $profile = $this->makeProfile(reputation: 5);
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertFalse($service->evaluate('user-1', Badge::Contributor));
    }

    #[Test]
    public function evaluateBugHunterAlwaysTrue(): void
    {
        $profile = $this->makeProfile();
        $this->profiles->method('findByUser')->willReturn($profile);

        $service = $this->makeService();

        self::assertTrue($service->evaluate('user-1', Badge::BugHunter));
    }

    #[Test]
    public function awardReturnsNullWhenBadgesDisabled(): void
    {
        $config = new ForumConfig(badges: new BadgeConfig(enabled: false));
        $service = $this->makeService(config: $config);

        self::assertNull($service->award('user-1', Badge::FirstPost));
    }

    #[Test]
    public function awardReturnsNullWhenAlreadyAwarded(): void
    {
        $userBadges = $this->createMock(UserBadgeRepositoryInterface::class);
        $userBadges->expects(self::once())->method('hasBadge')->with('user-1', Badge::FirstPost, null)->willReturn(true);

        $service = $this->makeService(userBadges: $userBadges);

        self::assertNull($service->award('user-1', Badge::FirstPost));
    }

    #[Test]
    public function awardReturnsNullWhenEvaluationFails(): void
    {
        $this->userBadges->method('hasBadge')->willReturn(false);
        $this->profiles->method('findByUser')->willReturn($this->makeProfile());

        $service = $this->makeService();

        self::assertNull($service->award('user-1', Badge::FirstPost));
    }

    #[Test]
    public function awardSavesAndDispatchesWhenCriteriaMet(): void
    {
        $profile = $this->makeProfile(postCount: 1);
        $this->profiles->method('findByUser')->willReturn($profile);

        $userBadges = $this->createMock(UserBadgeRepositoryInterface::class);
        $events = $this->createMock(EventDispatcherInterface::class);

        $userBadges->method('hasBadge')->willReturn(false);
        $userBadges->expects(self::once())->method('save')->with(self::isInstanceOf(UserBadge::class));
        $events->expects(self::once())->method('dispatch')->with(self::isInstanceOf(BadgeAwarded::class));

        $service = $this->makeService(userBadges: $userBadges, events: $events);

        $result = $service->award('user-1', Badge::FirstPost);

        self::assertInstanceOf(UserBadge::class, $result);
        self::assertSame(Badge::FirstPost, $result->badge);
        self::assertSame('user-1', $result->userId);
    }

    #[Test]
    public function revokeDeletesBadge(): void
    {
        $userBadge = UserBadge::award('ub-1', 'user-1', Badge::FirstPost);

        $userBadges = $this->createMock(UserBadgeRepositoryInterface::class);
        $userBadges->method('findByUser')->willReturn([$userBadge]);
        $userBadges->expects(self::once())->method('delete')->with($userBadge);

        $service = $this->makeService(userBadges: $userBadges);

        $service->revoke('user-1', Badge::FirstPost);
    }

    #[Test]
    public function revokeThrowsWhenBadgeNotFound(): void
    {
        $this->userBadges->method('findByUser')->willReturn([]);

        $service = $this->makeService();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessageIsOrContains('UserBadge not found');

        $service->revoke('user-1', Badge::FirstPost);
    }

    #[Test]
    public function getUserBadgesReturnsUserBadges(): void
    {
        $badge1 = UserBadge::award('ub-1', 'user-1', Badge::FirstPost);
        $badge2 = UserBadge::award('ub-2', 'user-1', Badge::Helpful);

        $userBadges = $this->createMock(UserBadgeRepositoryInterface::class);
        $userBadges->expects(self::once())->method('findByUser')->with('user-1')->willReturn([$badge1, $badge2]);

        $service = $this->makeService(userBadges: $userBadges);

        $result = $service->getUserBadges('user-1');

        self::assertCount(2, $result);
    }

    #[Test]
    public function hasBadgeDelegatesToRepository(): void
    {
        $userBadges = $this->createMock(UserBadgeRepositoryInterface::class);
        $userBadges->expects(self::once())->method('hasBadge')->with('user-1', Badge::FirstPost)->willReturn(true);

        $service = $this->makeService(userBadges: $userBadges);

        self::assertTrue($service->hasBadge('user-1', Badge::FirstPost));
    }
}
