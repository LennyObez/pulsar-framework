<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Api\ProfileApiController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ProfileApiController::class)]
final class ProfileApiControllerTest extends TestCase
{
    private function makeProfile(): ForumProfile
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new ForumProfile(
            id: 'profile-1',
            tenantId: null,
            userId: 'user-1',
            reputationScore: 150,
            postCount: 30,
            threadCount: 5,
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
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

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
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    private function makePost(): Post
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'Test body',
            bodyHtml: '<p>Test body</p>',
            isSolution: false,
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
    }

    private function makeController(
        ?ForumProfileRepositoryInterface $profileRepo = null,
        ?ThreadRepositoryInterface $threadRepo = null,
        ?PostRepositoryInterface $postRepo = null,
    ): ProfileApiController {
        return new ProfileApiController(
            profileRepository: $profileRepo ?? $this->createStub(ForumProfileRepositoryInterface::class),
            threadRepository: $threadRepo ?? $this->createStub(ThreadRepositoryInterface::class),
            postRepository: $postRepo ?? $this->createStub(PostRepositoryInterface::class),
            config: new ForumConfig(),
        );
    }

    #[Test]
    public function showReturns404WhenProfileNotFound(): void
    {
        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn(null);

        $controller = $this->makeController(profileRepo: $profileRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/profiles/user-1');

        $response = $controller->show($request, 'user-1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsProfileData(): void
    {
        $profile = $this->makeProfile();

        $profileRepo = $this->createStub(ForumProfileRepositoryInterface::class);
        $profileRepo->method('findByUser')->willReturn($profile);

        $controller = $this->makeController(profileRepo: $profileRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/profiles/user-1');

        $response = $controller->show($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('profile-1', $body['data']['id']);
        self::assertSame('user-1', $body['data']['user_id']);
        self::assertSame(150, $body['data']['reputation_score']);
        self::assertSame(30, $body['data']['post_count']);
        self::assertSame(5, $body['data']['thread_count']);
        self::assertFalse($body['data']['is_banned']);
        self::assertArrayHasKey('reputation_level', $body['data']);
    }

    #[Test]
    public function threadsReturnsPaginatedResults(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByAuthor')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = $this->makeController(threadRepo: $threadRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/profiles/user-1/threads');

        $response = $controller->threads($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('thread-1', $body['data'][0]['id']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function postsReturnsPaginatedResults(): void
    {
        $post = $this->makePost();

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByAuthor')->willReturn(new PaginationResult(
            items: [$post],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/profiles/user-1/posts');

        $response = $controller->posts($request, 'user-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('post-1', $body['data'][0]['id']);
        self::assertSame('thread-1', $body['data'][0]['thread_id']);
    }
}
