<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Account;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Domain\Badge;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Account\AccountController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(AccountController::class)]
final class AccountControllerTest extends TestCase
{
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeUnauthenticatedRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/account',
            headers: ['Accept' => 'application/json'],
        );
    }

    private function makeAuthenticatedRequest(string $uri = '/account', string $userId = 'user-1'): ServerRequest
    {
        $identity = $this->makeIdentity($userId);

        return new ServerRequest(
            method: 'GET',
            uri: $uri,
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $identity);
    }

    #[Test]
    public function profileThrowsWhenNotAuthenticated(): void
    {
        $controller = new AccountController(
            profileRepository: $this->createStub(ForumProfileRepositoryInterface::class),
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $this->createStub(PostRepositoryInterface::class),
            badgeRepository: $this->createStub(UserBadgeRepositoryInterface::class),
        );

        $this->expectException(AuthenticationException::class);

        $controller->profile($this->makeUnauthenticatedRequest());
    }

    #[Test]
    public function profileReturnsProfileDataWithBadges(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $profile = new ForumProfile(
            id: 'prof-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 200,
            postCount: 50,
            threadCount: 10,
            isBanned: false,
            banReason: null,
            bannedAt: null,
            banExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $badge = new UserBadge(
            id: 'ub-1',
            tenantId: null,
            userId: 'user-1',
            badge: Badge::Contributor,
            awardedAt: $now,
        );

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);
        $badgeRepo->method('findByUser')->willReturn([$badge]);

        $controller = new AccountController(
            profileRepository: $profileRepo,
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $this->createStub(PostRepositoryInterface::class),
            badgeRepository: $badgeRepo,
        );

        $response = $controller->profile($this->makeAuthenticatedRequest());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $body['profile']['reputation_score']);
        self::assertSame(50, $body['profile']['post_count']);
        self::assertCount(1, $body['badges']);
    }

    #[Test]
    public function profileReturnsNullProfileWhenNoneExists(): void
    {
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn(null);

        $badgeRepo = $this->createStub(UserBadgeRepositoryInterface::class);
        $badgeRepo->method('findByUser')->willReturn([]);

        $controller = new AccountController(
            profileRepository: $profileRepo,
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $this->createStub(PostRepositoryInterface::class),
            badgeRepository: $badgeRepo,
        );

        $response = $controller->profile($this->makeAuthenticatedRequest());

        $body = json_decode((string) $response->getBody(), true);
        self::assertNull($body['profile']);
    }

    #[Test]
    public function threadsReturnsUserThreads(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

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
            replyCount: 5,
            viewCount: 30,
            voteScore: 3,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByAuthor')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new AccountController(
            profileRepository: $this->createStub(ForumProfileRepositoryInterface::class),
            threadRepository: $threadRepo,
            postRepository: $this->createStub(PostRepositoryInterface::class),
            badgeRepository: $this->createStub(UserBadgeRepositoryInterface::class),
        );

        $response = $controller->threads($this->makeAuthenticatedRequest('/account/threads'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('My Threads', $body['page_title']);
        self::assertCount(1, $body['threads']);
        self::assertSame('My Thread', $body['threads'][0]['title']);
    }

    #[Test]
    public function postsReturnsUserPosts(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $post = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'My reply',
            bodyHtml: '<p>My reply</p>',
            isSolution: true,
            voteScore: 5,
            editCount: 0,
            editedBy: null,
            ipHash: 'abc',
            userAgentHash: 'def',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByAuthor')->willReturn(new PaginationResult(
            items: [$post],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new AccountController(
            profileRepository: $this->createStub(ForumProfileRepositoryInterface::class),
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $postRepo,
            badgeRepository: $this->createStub(UserBadgeRepositoryInterface::class),
        );

        $response = $controller->posts($this->makeAuthenticatedRequest('/account/posts'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('My Posts', $body['page_title']);
        self::assertCount(1, $body['posts']);
        self::assertTrue($body['posts'][0]['is_solution']);
    }

    #[Test]
    public function settingsReturnsSettingsPage(): void
    {
        $controller = new AccountController(
            profileRepository: $this->createStub(ForumProfileRepositoryInterface::class),
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $this->createStub(PostRepositoryInterface::class),
            badgeRepository: $this->createStub(UserBadgeRepositoryInterface::class),
        );

        $response = $controller->settings($this->makeAuthenticatedRequest('/account/settings'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Settings', $body['page_title']);
    }

    #[Test]
    public function threadsThrowsWhenNotAuthenticated(): void
    {
        $controller = new AccountController(
            profileRepository: $this->createStub(ForumProfileRepositoryInterface::class),
            threadRepository: $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $this->createStub(PostRepositoryInterface::class),
            badgeRepository: $this->createStub(UserBadgeRepositoryInterface::class),
        );

        $this->expectException(AuthenticationException::class);

        $controller->threads($this->makeUnauthenticatedRequest());
    }
}
