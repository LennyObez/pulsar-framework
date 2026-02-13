<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Notification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Config\BadgeConfig;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\ReputationChanged;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Internal\Notification\BadgeEvaluator;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use stdClass;

#[CoversClass(BadgeEvaluator::class)]
final class BadgeEvaluatorTest extends TestCase
{
    private BadgeServiceInterface&MockObject $badgeService;
    private PostRepositoryInterface&Stub $postRepo;
    private ThreadRepositoryInterface&Stub $threadRepo;
    private ForumProfileRepositoryInterface&Stub $profileRepo;
    private BadgeEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->badgeService = $this->createMock(BadgeServiceInterface::class);
        $this->postRepo = $this->createStub(PostRepositoryInterface::class);
        $this->threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $this->profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);

        $this->evaluator = new BadgeEvaluator(
            $this->badgeService,
            $this->postRepo,
            $this->threadRepo,
            $this->profileRepo,
            new BadgeConfig(),
        );
    }

    #[Test]
    public function awardsFirstPostBadgeOnPostCreated(): void
    {
        $event = new PostCreated(
            postId: 'post-001',
            threadId: 'thread-001',
            authorId: 'user-001',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturnMap([
            ['user-001', Badge::FirstPost, false],
            ['user-001', Badge::Multilingual, true], // already has this one
        ]);

        $this->postRepo->method('findByAuthor')->willReturn(
            new PaginationResult(items: [self::makePost('post-001', 'user-001')], total: 1, hasMore: false, perPage: 1),
        );

        $this->badgeService->expects(self::atLeastOnce())
            ->method('award')
            ->with('user-001', Badge::FirstPost, null);

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function doesNotDuplicateFirstPostBadge(): void
    {
        $event = new PostCreated(
            postId: 'post-002',
            threadId: 'thread-001',
            authorId: 'user-001',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturn(true);

        $this->badgeService->expects(self::never())->method('award');

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function awardsFirstAnswerBadgeOnSolutionAccepted(): void
    {
        $event = new PostAcceptedAsSolution(
            postId: 'post-001',
            threadId: 'thread-001',
            postAuthorId: 'user-001',
            acceptedBy: 'user-002',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturnMap([
            ['user-001', Badge::FirstAnswer, false],
            ['user-001', Badge::Solver, true], // already has solver
        ]);

        $this->badgeService->expects(self::atLeastOnce())
            ->method('award')
            ->with('user-001', Badge::FirstAnswer, null);

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function skipsEvaluationWhenBadgesDisabled(): void
    {
        $config = new BadgeConfig(enabled: false);
        $evaluator = new BadgeEvaluator(
            $this->badgeService,
            $this->postRepo,
            $this->threadRepo,
            $this->profileRepo,
            $config,
        );

        $event = new PostCreated(
            postId: 'post-001',
            threadId: 'thread-001',
            authorId: 'user-001',
        );

        $this->badgeService->expects(self::never())->method('award');

        $evaluator->handleEvent($event);
    }

    #[Test]
    public function awardsContributorBadgeOnReputationChange(): void
    {
        $event = new ReputationChanged(
            userId: 'user-001',
            previousScore: 5,
            newScore: 15,
            delta: 10,
            reason: 'Upvote received',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturnMap([
            ['user-001', Badge::Contributor, false],
        ]);

        $profile = self::profileWithReputation('user-001', 15);
        $this->profileRepo->method('findByUser')->willReturn($profile);

        $this->badgeService->expects(self::once())
            ->method('award')
            ->with('user-001', Badge::Contributor, null);

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function doesNotAwardContributorWhenReputationTooLow(): void
    {
        $event = new ReputationChanged(
            userId: 'user-001',
            previousScore: 2,
            newScore: 5,
            delta: 3,
            reason: 'Upvote',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturn(false);

        $profile = self::profileWithReputation('user-001', 5);
        $this->profileRepo->method('findByUser')->willReturn($profile);

        $this->badgeService->expects(self::never())->method('award');

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function ignoresUnknownEvents(): void
    {
        $event = new stdClass();

        $this->badgeService->expects(self::never())->method('award');

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function ignoresDownvoteEventsForHelpfulBadge(): void
    {
        $event = new VoteCast(
            voteId: 'vote-001',
            targetType: 'post',
            targetId: 'post-001',
            voterId: 'user-002',
            direction: VoteDirection::Down,
            targetAuthorId: 'user-001',
            tenantId: null,
        );

        $this->badgeService->expects(self::never())->method('award');

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function awardsHelpfulBadgeWhenUpvoteThresholdMet(): void
    {
        $event = new VoteCast(
            voteId: 'vote-001',
            targetType: 'post',
            targetId: 'post-001',
            voterId: 'user-002',
            direction: VoteDirection::Up,
            targetAuthorId: 'user-001',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturnMap([
            ['user-001', Badge::Helpful, false],
        ]);

        $post = self::makePost('post-001', 'user-001', voteScore: 10);
        $this->postRepo->method('findByAuthor')->willReturn(
            new PaginationResult(items: [$post], total: 1, hasMore: false, perPage: 100),
        );

        $this->badgeService->expects(self::once())
            ->method('award')
            ->with('user-001', Badge::Helpful, null);

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function skipsContributorWhenProfileNotFound(): void
    {
        $event = new ReputationChanged(
            userId: 'user-ghost',
            previousScore: 0,
            newScore: 100,
            delta: 100,
            reason: 'Bonus',
            tenantId: null,
        );

        $this->badgeService->method('hasBadge')->willReturn(false);
        $this->profileRepo->method('findByUser')->willReturn(null);

        $this->badgeService->expects(self::never())->method('award');

        $this->evaluator->handleEvent($event);
    }

    private static function makePost(
        string $id,
        string $authorId,
        bool $isSolution = false,
        int $voteScore = 0,
    ): Post {
        $now = new DateTimeImmutable();

        return new Post(
            id: $id,
            tenantId: null,
            threadId: 'thread-001',
            parentId: null,
            authorId: $authorId,
            body: 'Post body',
            bodyHtml: '<p>Post body</p>',
            isSolution: $isSolution,
            voteScore: $voteScore,
            editCount: 0,
            editedBy: null,
            ipHash: 'hash',
            userAgentHash: 'hash',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            version: 1,
        );
    }

    private static function profileWithReputation(string $userId, int $score): ForumProfile
    {
        $now = new DateTimeImmutable();

        return new ForumProfile(
            id: 'profile-' . $userId,
            tenantId: null,
            userId: $userId,
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
}
