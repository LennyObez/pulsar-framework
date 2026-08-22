<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Admin\PostController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(PostController::class)]
final class AdminPostControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAllowGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    private function makePost(string $id = 'post-1', bool $deleted = false): Post
    {
        $now = new DateTimeImmutable('2025-06-01 10:00:00');

        return new Post(
            id: $id,
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
            deletedAt: $deleted ? $now : null,
            version: 1,
        );
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?PostRepositoryInterface $postRepo = null,
        ?ForumServiceInterface $forumService = null,
        ?MarkdownRendererInterface $markdown = null,
    ): PostController {
        if ($markdown === null) {
            $markdown = $this->createStub(MarkdownRendererInterface::class);
            $markdown->method('render')->willReturn('<p>Updated</p>');
        }

        return new PostController(
            postRepository: $postRepo ?? $this->createStub(PostRepositoryInterface::class),
            forumService: $forumService ?? $this->createStub(ForumServiceInterface::class),
            markdown: $markdown,
            config: new ForumConfig(),
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        string $uri = '/admin/forum/posts',
        array $queryParams = [],
        ?array $parsedBody = null,
    ): ServerRequest {
        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: ['Accept' => 'application/json'],
            queryParams: $queryParams,
        )->withAttribute('identity', $this->makeIdentity());

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $this->expectException(AuthorizationException::class);

        $controller->index($this->makeRequest(), 'thread-1');
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

        $response = $controller->index($this->makeRequest(), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('post-1', $body['data'][0]['id']);
        self::assertSame('thread-1', $body['data'][0]['thread_id']);
        self::assertSame('Hello world', $body['data'][0]['body']);
        self::assertSame('<p>Hello world</p>', $body['data'][0]['body_html']);
        self::assertSame('thread-1', $body['thread_id']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(postRepo: $postRepo);

        $response = $controller->show($this->makeRequest(), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsPost(): void
    {
        $post = $this->makePost();

        $postRepo = $this->createStub(PostRepositoryInterface::class);
        $postRepo->method('findById')->willReturn($post);

        $controller = $this->makeController(postRepo: $postRepo);

        $response = $controller->show($this->makeRequest(), 'post-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('post-1', $body['post']['id']);
        self::assertSame(3, $body['post']['vote_score']);
        self::assertFalse($body['post']['is_solution']);
        self::assertFalse($body['post']['is_deleted']);
    }

    #[Test]
    public function editWithEmptyBodyReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->edit(
            $this->makeRequest('PUT', parsedBody: ['body' => '']),
            'post-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Post body is required', $body['error']);
    }

    #[Test]
    public function editWithMissingBodyReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->edit(
            $this->makeRequest('PUT', parsedBody: []),
            'post-1',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function editReturnsUpdatedPost(): void
    {
        $now = new DateTimeImmutable('2025-06-01 12:00:00');
        $editedPost = new Post(
            id: 'post-1',
            tenantId: null,
            threadId: 'thread-1',
            parentId: null,
            authorId: 'user-1',
            body: 'Updated content',
            bodyHtml: '<p>Updated content</p>',
            isSolution: false,
            voteScore: 3,
            editCount: 1,
            editedBy: 'admin-1',
            ipHash: 'iphash',
            userAgentHash: 'uahash',
            editedAt: $now,
            editWindowExpiresAt: null,
            createdAt: new DateTimeImmutable('2025-06-01 10:00:00'),
            updatedAt: $now,
            deletedAt: null,
            version: 2,
        );

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('editPost')->willReturn($editedPost);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->edit(
            $this->makeRequest('PUT', parsedBody: ['body' => 'Updated content']),
            'post-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Updated content', $body['data']['body']);
        self::assertSame(1, $body['data']['edit_count']);
        self::assertSame('admin-1', $body['data']['edited_by']);
    }

    #[Test]
    public function editServiceExceptionReturns422(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('editPost')->willThrowException(
            new ForumException('Post not found'),
        );

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->edit(
            $this->makeRequest('PUT', parsedBody: ['body' => 'New content']),
            'bad-id',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccess(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->delete($this->makeRequest('DELETE'), 'post-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('deleted', $body['data']['status']);
        self::assertSame('post-1', $body['data']['id']);
    }

    #[Test]
    public function deleteServiceExceptionReturns422(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('deletePost')->willThrowException(
            new ForumException('Post not found'),
        );

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->delete($this->makeRequest('DELETE'), 'bad-id');

        self::assertSame(422, $response->getStatusCode());
    }
}
