<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Page\ThreadPageController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ThreadPageController::class)]
final class ThreadPageControllerTest extends TestCase
{
    private function makeThread(string $slug = 'test-thread'): Thread
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: $slug,
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 3,
            viewCount: 50,
            voteScore: 7,
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
        $now = new DateTimeImmutable('2025-01-15 13:00:00');

        return new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-2',
            body: 'Hello world',
            bodyHtml: '<p>Hello world</p>',
            isSolution: false,
            voteScore: 2,
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
    }

    #[Test]
    public function showReturnsJsonWithThreadAndPosts(): void
    {
        $thread = $this->makeThread();
        $post = $this->makePost();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn($thread);

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByThread')->willReturn(new PaginationResult(
            items: [$post],
            total: 1,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ThreadPageController(
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/test-thread',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'test-thread');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('thread-1', $body['thread']['id']);
        self::assertSame('Test Thread', $body['thread']['title']);
        self::assertCount(1, $body['posts']);
        self::assertSame('post-1', $body['posts'][0]['id']);
        self::assertSame('<p>Hello world</p>', $body['posts'][0]['body_html']);
    }

    #[Test]
    public function showReturns404WhenThreadNotFound(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn(null);

        $postRepo = $this->createStub(PostRepositoryInterface::class);

        $controller = new ThreadPageController(
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/nonexistent',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Thread Not Found', $body['page_title']);
    }

    #[Test]
    #[DataProvider('paginationProvider')]
    public function showClampsPaginationParameters(array $queryParams, int $expectedPage): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn($thread);

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByThread')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ThreadPageController(
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/test-thread',
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        );

        $response = $controller->show($request, 'test-thread');

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($expectedPage, $body['page']);
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function paginationProvider(): iterable
    {
        yield 'no page' => [[], 1];
        yield 'page 3' => [['page' => '3'], 3];
        yield 'negative page clamps to 1' => [['page' => '-5'], 1];
        yield 'zero clamps to 1' => [['page' => '0'], 1];
    }

    #[Test]
    public function showSetsNoindexOnPaginatedPages(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn($thread);

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByThread')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ThreadPageController(
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/test-thread?page=2',
            headers: ['Accept' => 'application/json'],
            queryParams: ['page' => '2'],
        );

        $response = $controller->show($request, 'test-thread');

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('noindex, follow', $body['meta_robots']);
    }

    #[Test]
    public function showSetsIndexFollowOnFirstPage(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn($thread);

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByThread')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ThreadPageController(
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/test-thread',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'test-thread');

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('index, follow', $body['meta_robots']);
    }

    #[Test]
    public function showIncludesMetaDescriptionAndCanonicalUrl(): void
    {
        $thread = $this->makeThread('my-thread-slug');

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findBySlug')->willReturn($thread);

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findByThread')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
        ));

        $controller = new ThreadPageController(
            threadRepository: $threadRepo,
            postRepository: $postRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/my-thread-slug',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'my-thread-slug');

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('meta_description', $body);
        self::assertNotEmpty($body['meta_description']);
        self::assertArrayHasKey('canonical_url', $body);
        self::assertSame('/t/my-thread-slug', $body['canonical_url']);
    }
}
