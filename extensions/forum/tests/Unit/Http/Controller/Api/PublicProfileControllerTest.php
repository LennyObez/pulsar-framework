<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Api\PublicProfileController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(PublicProfileController::class)]
final class PublicProfileControllerTest extends TestCase
{
    private function makeProfile(): ForumProfile
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 500,
            postCount: 100,
            threadCount: 20,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeThread(): Thread
    {
        $now = new DateTimeImmutable('2025-06-01 10:00:00');

        return new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 5,
            viewCount: 100,
            voteScore: 10,
            lastActivityAt: $now,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    private function makePost(): Post
    {
        $now = new DateTimeImmutable('2025-06-01 10:00:00');

        return new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'Hello world',
            bodyHtml: '<p>Hello world</p>',
            isSolution: false,
            voteScore: 3,
            editCount: 0,
            editedBy: null,
            ipHash: 'iphash',
            userAgentHash: 'uahash',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
            version: 1,
        );
    }

    private function makeUserBadge(): UserBadge
    {
        return new UserBadge(
            id: 'ub-1',
            tenantId: null,
            userId: 'user-1',
            badge: Badge::Solver,
            awardedAt: new DateTimeImmutable('2025-03-01'),
        );
    }

    private function makeController(
        ?ForumProfileRepositoryInterface $profileRepo = null,
        ?UserBadgeRepositoryInterface $badgeRepo = null,
        ?ThreadRepositoryInterface $threadRepo = null,
        ?PostRepositoryInterface $postRepo = null,
    ): PublicProfileController {
        if ($badgeRepo === null) {
            $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);
            $badgeRepo->method('findByUser')->willReturn([]);
        }

        if ($threadRepo === null) {
            $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
            $threadRepo->method('findByAuthor')->willReturn(new PaginationResult([], 0, false, 20));
        }

        if ($postRepo === null) {
            $postRepo = $this->createStub(PostRepositoryInterface::class);
            $postRepo->method('findByAuthor')->willReturn(new PaginationResult([], 0, false, 20));
        }

        return new PublicProfileController(
            profileRepository: $profileRepo ?? $this->createStub(ForumProfileRepositoryInterface::class),
            badgeRepository: $badgeRepo,
            threadRepository: $threadRepo,
            postRepository: $postRepo,
        );
    }

    private function makeRequest(array $queryParams = []): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/users/user-1',
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        );
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn(null);

        $controller = $this->makeController(profileRepo: $profileRepo);

        $response = $controller->show($this->makeRequest(), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsProfileWithBadgesAndStats(): void
    {
        $profile = $this->makeProfile();
        $badge = $this->makeUserBadge();

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);
        $badgeRepo->method('findByUser')->willReturn([$badge]);

        $controller = $this->makeController(profileRepo: $profileRepo, badgeRepo: $badgeRepo);

        $response = $controller->show($this->makeRequest(), 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        // Profile data
        self::assertSame('user-1', $body['data']['profile']['user_id']);
        self::assertSame(500, $body['data']['profile']['reputation_score']);

        // Badges
        self::assertCount(1, $body['data']['badges']);
        self::assertSame('solver', $body['data']['badges'][0]['badge']);
        self::assertSame('Solver', $body['data']['badges'][0]['label']);

        // Stats
        self::assertSame(100, $body['data']['stats']['total_posts']);
        self::assertSame(20, $body['data']['stats']['total_threads']);
        self::assertSame(1, $body['data']['stats']['badge_count']);
    }

    #[Test]
    public function activityReturns404WhenNotFound(): void
    {
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn(null);

        $controller = $this->makeController(profileRepo: $profileRepo);

        $response = $controller->activity($this->makeRequest(), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function activityReturnsTimeline(): void
    {
        $profile = $this->makeProfile();
        $thread = $this->makeThread();
        $post = $this->makePost();
        $badge = $this->makeUserBadge();

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByAuthor')->willReturn(new PaginationResult([$thread], 1, false, 20));

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByAuthor')->willReturn(new PaginationResult([$post], 1, false, 20));

        $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);
        $badgeRepo->method('findByUser')->willReturn([$badge]);

        $controller = $this->makeController(
            profileRepo: $profileRepo,
            badgeRepo: $badgeRepo,
            threadRepo: $threadRepo,
            postRepo: $postRepo,
        );

        $response = $controller->activity($this->makeRequest(), 'user-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        // Timeline should have 3 items: thread + reply + badge, sorted by date desc
        self::assertCount(3, $body['data']);
        self::assertArrayHasKey('pagination', $body);
        self::assertSame(3, $body['pagination']['total']);

        // Check types exist in the timeline
        $types = array_column($body['data'], 'type');
        self::assertContains('thread', $types);
        self::assertContains('reply', $types);
        self::assertContains('badge', $types);
    }

    #[Test]
    public function activityReturnsEmptyTimeline(): void
    {
        $profile = $this->makeProfile();

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $controller = $this->makeController(profileRepo: $profileRepo);

        $response = $controller->activity($this->makeRequest(), 'user-1');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(0, $body['data']);
        self::assertSame(0, $body['pagination']['total']);
    }
}
