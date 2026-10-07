<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\PostApiController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(PostApiController::class)]
final class PostApiControllerTest extends TestCase
{
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makePost(
        string $id = 'post-1',
        string $authorId = 'user-1',
        ?DateTimeImmutable $deletedAt = null,
    ): Post {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Post(
            id: $id,
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: $authorId,
            body: 'Test body',
            bodyHtml: '<p>Test body</p>',
            isSolution: false,
            voteScore: 0,
            editCount: 0,
            editedBy: null,
            ipHash: 'abc',
            userAgentHash: 'def',
            editedAt: null,
            editWindowExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $deletedAt,
        );
    }

    private function makeThread(string $authorId = 'user-1'): Thread
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: $authorId,
            title: 'Test',
            slug: 'test',
            type: ThreadType::Question,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 1,
            viewCount: 10,
            voteScore: 0,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );
    }

    private function makeController(
        ?PostRepositoryInterface $postRepo = null,
        ?ForumServiceInterface $forumService = null,
        ?GateInterface $gate = null,
        ?ThreadRepositoryInterface $threadRepo = null,
    ): PostApiController {
        $markdown = $this->createStub(MarkdownRendererInterface::class);
        $markdown->method('render')->willReturnCallback(static fn(string $s) => "<p>{$s}</p>");

        return new PostApiController(
            postRepository: $postRepo ?? $this->createStub(PostRepositoryInterface::class),
            forumService: $forumService ?? $this->createStub(ForumServiceInterface::class),
            markdown: $markdown,
            bodyPolicy: new ForumBodyPolicy(),
            config: new ForumConfig(),
            threadRepository: $threadRepo ?? $this->createStub(ThreadRepositoryInterface::class),
            gate: $gate,
        );
    }

    #[Test]
    public function indexReturnsPaginatedPosts(): void
    {
        $post = $this->makePost();

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByThread')->willReturn(new PaginationResult(
            items: [$post],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads/thread-1/posts');

        $response = $controller->index($request, 'thread-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Total-Count'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('post-1', $body['data'][0]['id']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function createWithoutAuthThrows(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-1/posts',
        )->withParsedBody(['body' => 'Test']);

        $this->expectException(ForumException::class);

        $controller->create($request, 'thread-1');
    }

    #[Test]
    public function createWithNullBodyReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-1/posts',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->create($request, 'thread-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createWithEmptyBodyReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-1/posts',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['body' => '']);

        $response = $controller->create($request, 'thread-1');

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('body', $body['details']);
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $post = $this->makePost();

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('createPost')->willReturn($post);

        $controller = $this->makeController(forumService: $forumService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-1/posts',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['body' => 'Test body']);

        $response = $controller->create($request, 'thread-1');

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('post-1', $body['data']['id']);
    }

    #[Test]
    public function showReturnsPost(): void
    {
        $post = $this->makePost();

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/posts/post-1');

        $response = $controller->show($request, 'post-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('post-1', $body['data']['id']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/posts/nonexistent');

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturns404WhenDeleted(): void
    {
        $post = $this->makePost(deletedAt: new DateTimeImmutable());

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/posts/post-1');

        $response = $controller->show($request, 'post-1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateWithNullBodyReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/posts/post-1',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->update($request, 'post-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function updateWithEmptyBodyFieldReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/posts/post-1',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['body' => '']);

        $response = $controller->update($request, 'post-1');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function deleteByNonOwnerNonModeratorReturns403(): void
    {
        $post = $this->makePost(authorId: 'other-user');

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = $this->makeController(postRepo: $postRepo, gate: $gate);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/post-1',
        )->withAttribute('identity', $this->makeIdentity('user-1'));

        $response = $controller->delete($request, 'post-1');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deleteByOwnerReturnsSuccess(): void
    {
        $post = $this->makePost(authorId: 'user-1');

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $forumService = $this->createStub(ForumServiceInterface::class);

        $controller = $this->makeController(postRepo: $postRepo, forumService: $forumService);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/post-1',
        )->withAttribute('identity', $this->makeIdentity('user-1'));

        $response = $controller->delete($request, 'post-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('deleted', $body['data']['status']);
    }

    #[Test]
    public function deleteReturns404WhenNotFound(): void
    {
        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/nonexistent',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function acceptReturns404WhenPostNotFound(): void
    {
        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(postRepo: $postRepo);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/nonexistent/accept',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->accept($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function acceptReturnsSuccessWhenCalledByThreadOwner(): void
    {
        // Arrange: post authored by user-2, thread authored by user-1, caller is user-1 (thread owner)
        $post = $this->makePost(authorId: 'user-2');
        $thread = $this->makeThread(authorId: 'user-1');

        $solvedThread = new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test',
            slug: 'test',
            type: ThreadType::Question,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: 'post-1',
            replyCount: 1,
            viewCount: 10,
            voteScore: 0,
            lastActivityAt: new DateTimeImmutable('2025-01-15 12:00:00'),
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: new DateTimeImmutable('2025-01-15 12:00:00'),
            updatedAt: new DateTimeImmutable('2025-01-15 12:00:00'),
            deletedAt: null,
        );

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('acceptSolution')->willReturn($solvedThread);

        $controller = $this->makeController(
            postRepo: $postRepo,
            forumService: $forumService,
            threadRepo: $threadRepo,
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-1/accept',
        )->withAttribute('identity', $this->makeIdentity('user-1'));

        // Act
        $response = $controller->accept($request, 'post-1');

        // Assert
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('thread-1', $body['data']['thread_id']);
        self::assertSame('post-1', $body['data']['solved_post_id']);
        self::assertSame('accepted', $body['data']['status']);
    }

    #[Test]
    public function acceptReturns403WhenCallerIsNotThreadOwner(): void
    {
        // Arrange: post exists, thread authored by user-1, but caller is user-2 (not the owner)
        $post = $this->makePost(authorId: 'user-3');
        $thread = $this->makeThread(authorId: 'user-1');

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $controller = $this->makeController(postRepo: $postRepo, threadRepo: $threadRepo);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-1/accept',
        )->withAttribute('identity', $this->makeIdentity('user-2'));

        // Act
        $response = $controller->accept($request, 'post-1');

        // Assert
        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Forbidden', $body['error']);
    }

    #[Test]
    public function createServiceExceptionReturns422(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('createPost')->willThrowException(
            ForumException::unauthorized('Thread is locked'),
        );

        $controller = $this->makeController(forumService: $forumService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-1/posts',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['body' => 'Test body']);

        $response = $controller->create($request, 'thread-1');

        self::assertSame(422, $response->getStatusCode());
    }
}
