<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\PostApiController;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Api\Pagination\PaginationResult;

#[CoversClass(PostApiController::class)]
final class PostApiControllerTest extends TestCase
{
    private PostRepositoryInterface&Stub $postRepository;
    private ForumServiceInterface&Stub $forumService;
    private MarkdownRendererInterface&Stub $markdown;
    private ForumConfig $config;
    private PostApiController $controller;

    protected function setUp(): void
    {
        $this->postRepository = $this->createStub(PostRepositoryInterface::class);
        $this->forumService = $this->createStub(ForumServiceInterface::class);
        $this->markdown = $this->createStub(MarkdownRendererInterface::class);
        $this->markdown->method('render')->willReturnCallback(static fn(string $md) => "<p>{$md}</p>");
        $this->config = new ForumConfig();

        $this->controller = new PostApiController(
            $this->postRepository,
            $this->forumService,
            $this->markdown,
            $this->config,
        );
    }

    #[Test]
    public function indexReturnsPaginatedPosts(): void
    {
        $posts = [$this->createPost('post-1'), $this->createPost('post-2')];
        $result = new PaginationResult(
            items: $posts,
            total: 2,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );
        $this->postRepository->method('findByThread')->willReturn($result);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads/thread-001/posts');
        $response = $this->controller->index($request, 'thread-001');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertCount(2, $data['data']);
        self::assertSame('2', $response->getHeaderLine('X-Total-Count'));
    }

    #[Test]
    public function showReturnsPostWhenFound(): void
    {
        $post = $this->createPost('post-show');
        $this->postRepository->method('findById')->willReturn($post);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/posts/post-show');
        $response = $this->controller->show($request, 'post-show');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('post-show', $data['data']['id']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $this->postRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/posts/nonexistent');
        $response = $this->controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function createRequiresAuthentication(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/posts',
            parsedBody: ['body' => 'Hello'],
        );

        $this->expectException(ForumException::class);
        $this->controller->create($request, 'thread-001');
    }

    #[Test]
    public function createReturns422WhenBodyMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/posts',
            parsedBody: [],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->create($request, 'thread-001');

        self::assertSame(422, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertArrayHasKey('body', $data['details']);
    }

    #[Test]
    public function createReturns400ForInvalidParsedBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/posts',
            parsedBody: null,
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->create($request, 'thread-001');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $post = $this->createPost('post-new');
        $this->forumService->method('createPost')->willReturn($post);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-001/posts',
            parsedBody: ['body' => 'Hello world'],
            attributes: ['identity' => $this->createIdentity('user-001')],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            headers: ['User-Agent' => 'TestClient/1.0'],
        );

        $response = $this->controller->create($request, 'thread-001');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('post-new', $data['data']['id']);
    }

    #[Test]
    public function deleteReturns403ForNonOwnerNonModerator(): void
    {
        $post = $this->createPost('post-del', authorId: 'user-owner');
        $this->postRepository->method('findById')->willReturn($post);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = new PostApiController(
            $this->postRepository,
            $this->forumService,
            $this->markdown,
            $this->config,
            $gate,
        );

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/post-del',
            attributes: ['identity' => $this->createIdentity('user-other')],
        );

        $response = $controller->delete($request, 'post-del');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deleteAllowsOwner(): void
    {
        $post = $this->createPost('post-own-del', authorId: 'user-owner');
        $this->postRepository->method('findById')->willReturn($post);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/posts/post-own-del',
            attributes: ['identity' => $this->createIdentity('user-owner')],
        );

        $response = $this->controller->delete($request, 'post-own-del');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('deleted', $data['data']['status']);
    }

    #[Test]
    public function acceptReturnsAcceptedSolution(): void
    {
        $post = $this->createPost('post-accept');
        $this->postRepository->method('findById')->willReturn($post);

        $thread = \Pulsar\Extension\Forum\Thread\Thread::create(
            id: 'thread-001',
            categoryId: 'cat-001',
            authorId: 'user-001',
            title: 'Test',
            slug: 'test',
            type: \Pulsar\Extension\Forum\Domain\ThreadType::Question,
            ipHash: 'h',
            userAgentHash: 'h',
        );
        $this->forumService->method('acceptSolution')->willReturn($thread);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/posts/post-accept/accept',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->accept($request, 'post-accept');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('accepted', $data['data']['status']);
    }

    #[Test]
    public function updateReturns422WhenBodyMissing(): void
    {
        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/posts/post-1',
            parsedBody: [],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->update($request, 'post-1');

        self::assertSame(422, $response->getStatusCode());
    }

    private function createPost(string $id, string $authorId = 'user-001'): Post
    {
        return Post::create(
            id: $id,
            threadId: 'thread-001',
            authorId: $authorId,
            body: 'Test body',
            bodyHtml: '<p>Test body</p>',
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
    }

    private function createIdentity(string $id): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('displayName')->willReturn('Test User');
        $identity->method('roles')->willReturn([]);
        $identity->method('hasRole')->willReturn(false);
        $identity->method('twoFactorStatus')->willReturn(TwoFactorStatus::Disabled);
        $identity->method('attributes')->willReturn([]);

        return $identity;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
