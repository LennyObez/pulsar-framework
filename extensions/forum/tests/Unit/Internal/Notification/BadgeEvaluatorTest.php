<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Internal\Notification;

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
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Internal\Notification\BadgeEvaluator;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use stdClass;

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

        $badgeConfig = BadgeConfig::fromArray([
            'enabled' => true,
            'helpful_upvote_threshold' => 5,
            'solver_accepted_answer_threshold' => 3,
            'multilingual_locale_threshold' => 3,
        ]);

        $this->evaluator = new BadgeEvaluator(
            $this->badgeService,
            $this->postRepo,
            $this->threadRepo,
            $this->profileRepo,
            $badgeConfig,
        );
    }

    #[Test]
    public function handleEventIgnoresWhenBadgesDisabled(): void
    {
        $config = BadgeConfig::fromArray(['enabled' => false]);
        $evaluator = new BadgeEvaluator(
            $this->badgeService,
            $this->postRepo,
            $this->threadRepo,
            $this->profileRepo,
            $config,
        );

        $this->badgeService->expects($this->never())->method('award');

        $event = new PostCreated(
            postId: 'p1',
            threadId: 't1',
            authorId: 'u1',
            tenantId: null,
        );

        $evaluator->handleEvent($event);
    }

    #[Test]
    public function handleEventAwardsFirstPostBadge(): void
    {
        $this->badgeService->method('hasBadge')->willReturn(false);
        $this->postRepo->method('findByAuthor')->willReturn(
            new PaginationResult(items: [], total: 1, hasMore: false, perPage: 1),
        );
        $this->threadRepo->method('findByAuthor')->willReturn(
            new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100),
        );

        $this->badgeService->expects($this->atLeastOnce())
            ->method('award')
            ->with('author-1', Badge::FirstPost, null);

        $event = new PostCreated(
            postId: 'p1',
            threadId: 't1',
            authorId: 'author-1',
            tenantId: null,
        );

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function handleEventAwardsFirstAnswerOnSolutionAccepted(): void
    {
        $this->badgeService->method('hasBadge')->willReturn(false);
        $this->postRepo->method('findByAuthor')->willReturn(
            new PaginationResult(items: [], total: 1, hasMore: false, perPage: 100),
        );

        $this->badgeService->expects($this->atLeastOnce())
            ->method('award')
            ->with('answerer', Badge::FirstAnswer, 'tenant-1');

        $event = new PostAcceptedAsSolution(
            postId: 'p1',
            threadId: 't1',
            postAuthorId: 'answerer',
            acceptedBy: 'op',
            tenantId: 'tenant-1',
        );

        $this->evaluator->handleEvent($event);
    }

    #[Test]
    public function handleEventSkipsUnknownEventTypes(): void
    {
        $this->badgeService->expects($this->never())->method('award');

        $this->evaluator->handleEvent(new stdClass());
    }

    #[Test]
    public function handleEventIgnoresDownvotesForHelpfulBadge(): void
    {
        $this->badgeService->expects($this->never())->method('award');

        $event = new VoteCast(
            voteId: 'v1',
            targetType: 'post',
            targetId: 'p1',
            voterId: 'voter',
            direction: VoteDirection::Down,
            targetAuthorId: 'author',
            tenantId: null,
        );

        $this->evaluator->handleEvent($event);
    }
}
