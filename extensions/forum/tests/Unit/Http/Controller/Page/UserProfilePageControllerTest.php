<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Page;

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
use Pulsar\Extension\Forum\Http\Controller\Page\UserProfilePageController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(UserProfilePageController::class)]
final class UserProfilePageControllerTest extends TestCase
{
    #[Test]
    public function showReturns404WhenProfileNotFound(): void
    {
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn(null);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);

        $controller = new UserProfilePageController(
            profileRepository: $profileRepo,
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            badgeRepository: $badgeRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/u/nonexistent',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('User Not Found', $body['page_title']);
    }

    #[Test]
    public function showReturnsProfileWithThreadsPostsAndBadges(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $profile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 150,
            postCount: 25,
            threadCount: 5,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $thread = new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'My Thread',
            slug: 'my-thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 3,
            viewCount: 20,
            voteScore: 5,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'My reply',
            bodyHtml: '<p>My reply</p>',
            isSolution: false,
            voteScore: 3,
            editCount: 0,
            editedBy: null,
            ipHash: 'ghi',
            userAgentHash: 'jkl',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $badge = new UserBadge(
            id: 'badge-1',
            tenantId: null,
            userId: 'user-1',
            badge: Badge::FirstPost,
            awardedAt: $now,
        );

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByAuthor')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 10,
        ));

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByAuthor')->willReturn(new PaginationResult(
            items: [$post],
            total: 1,
            hasMore: false,
            perPage: 10,
        ));

        $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);
        $badgeRepo->method('findByUser')->willReturn([$badge]);

        $controller = new UserProfilePageController(
            profileRepository: $profileRepo,
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            badgeRepository: $badgeRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/u/user-1',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(150, $body['profile']['reputation_score']);
        self::assertSame(25, $body['profile']['post_count']);
        self::assertFalse($body['profile']['is_banned']);
        self::assertCount(1, $body['threads']);
        self::assertSame('My Thread', $body['threads'][0]['title']);
        self::assertCount(1, $body['posts']);
        self::assertSame('<p>My reply</p>', $body['posts'][0]['body_html']);
        self::assertCount(1, $body['badges']);
    }
}
