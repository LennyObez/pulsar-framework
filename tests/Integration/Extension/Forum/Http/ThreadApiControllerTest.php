<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Api\ThreadApiController;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ThreadApiController::class)]
final class ThreadApiControllerTest extends TestCase
{
    private ThreadRepositoryInterface&Stub $threadRepository;
    private ThreadSubscriptionRepositoryInterface&Stub $subscriptionRepository;
    private ForumServiceInterface&Stub $forumService;
    private MarkdownRendererInterface&Stub $markdown;
    private ForumConfig $config;
    private ThreadApiController $controller;

    protected function setUp(): void
    {
        $this->threadRepository = $this->createStub(ThreadRepositoryInterface::class);
        $this->subscriptionRepository = $this->createStub(ThreadSubscriptionRepositoryInterface::class);
        $this->forumService = $this->createStub(ForumServiceInterface::class);
        $this->markdown = $this->createStub(MarkdownRendererInterface::class);
        $this->markdown->method('render')->willReturnCallback(static fn(string $md) => "<p>{$md}</p>");
        $this->config = new ForumConfig();

        $this->controller = new ThreadApiController(
            $this->threadRepository,
            $this->subscriptionRepository,
            $this->forumService,
            $this->markdown,
            new ForumBodyPolicy(),
            $this->config,
        );
    }

    #[Test]
    public function showReturnsThreadWhenFound(): void
    {
        $thread = $this->createThread('thread-001');
        $this->threadRepository->method('findById')->willReturn($thread);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads/thread-001');
        $response = $this->controller->show($request, 'thread-001');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('thread-001', $data['data']['id']);
        self::assertSame('Test Thread', $data['data']['title']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $this->threadRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads/nonexistent');
        $response = $this->controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('Thread not found', $data['error']);
    }

    #[Test]
    public function createRequiresAuthentication(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            parsedBody: ['title' => 'Test', 'slug' => 'test', 'category_id' => 'cat-1'],
        );

        $this->expectException(ForumException::class);
        $this->controller->create($request);
    }

    #[Test]
    public function createReturnsValidationErrorsForMissingFields(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            parsedBody: [],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->create($request);

        self::assertSame(422, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertSame('Validation failed', $data['error']);
        self::assertIsArray($data['details']);
        self::assertArrayHasKey('title', $data['details']);
        self::assertArrayHasKey('slug', $data['details']);
        self::assertArrayHasKey('category_id', $data['details']);
    }

    #[Test]
    public function createReturns400ForInvalidBody(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            parsedBody: null,
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $thread = $this->createThread('thread-new');
        $this->forumService->method('createThread')->willReturn($thread);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
            parsedBody: [
                'title' => 'Test Thread',
                'slug' => 'test-thread',
                'category_id' => 'cat-001',
                'body' => 'Hello world',
            ],
            attributes: ['identity' => $this->createIdentity('user-001')],
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            headers: ['User-Agent' => 'TestClient/1.0'],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('thread-new', $data['data']['id']);
    }

    #[Test]
    public function updateReturns403ForNonOwner(): void
    {
        $thread = $this->createThread('thread-owned', authorId: 'user-owner');
        $this->threadRepository->method('findById')->willReturn($thread);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/threads/thread-owned',
            parsedBody: ['title' => 'Updated'],
            attributes: ['identity' => $this->createIdentity('user-other')],
        );

        $response = $this->controller->update($request, 'thread-owned');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function updateReturns404WhenNotFound(): void
    {
        $this->threadRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/threads/nonexistent',
            parsedBody: ['title' => 'Updated'],
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturns403ForNonOwnerNonModerator(): void
    {
        $thread = $this->createThread('thread-del', authorId: 'user-owner');
        $this->threadRepository->method('findById')->willReturn($thread);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = new ThreadApiController(
            $this->threadRepository,
            $this->subscriptionRepository,
            $this->forumService,
            $this->markdown,
            new ForumBodyPolicy(),
            $this->config,
            $gate,
        );

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/threads/thread-del',
            attributes: ['identity' => $this->createIdentity('user-other')],
        );

        $response = $controller->delete($request, 'thread-del');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deleteAllowsOwner(): void
    {
        $thread = $this->createThread('thread-own-del', authorId: 'user-owner');
        $this->threadRepository->method('findById')->willReturn($thread);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/threads/thread-own-del',
            attributes: ['identity' => $this->createIdentity('user-owner')],
        );

        $response = $this->controller->delete($request, 'thread-own-del');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertSame('deleted', $data['data']['status']);
    }

    #[Test]
    public function indexReturnsPaginatedResults(): void
    {
        $threads = [
            $this->createThread('t-1'),
            $this->createThread('t-2'),
        ];
        $result = new PaginationResult(
            items: $threads,
            total: 2,
            hasMore: false,
            perPage: 25,
            currentPage: 1,
            lastPage: 1,
        );
        $this->threadRepository->method('findRecent')->willReturn($result);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertCount(2, $data['data']);
        self::assertSame('2', $response->getHeaderLine('X-Total-Count'));
    }

    #[Test]
    public function subscribeCreatesSubscription(): void
    {
        $thread = $this->createThread('thread-sub');
        $this->threadRepository->method('findById')->willReturn($thread);
        $this->subscriptionRepository->method('findByUserAndThread')->willReturn(null);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/thread-sub/subscribe',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->subscribe($request, 'thread-sub');

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertTrue($data['data']['subscribed']);
    }

    #[Test]
    public function unsubscribeReturnsUnsubscribed(): void
    {
        $this->subscriptionRepository->method('findByUserAndThread')->willReturn(null);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/threads/thread-unsub/subscribe',
            attributes: ['identity' => $this->createIdentity('user-001')],
        );

        $response = $this->controller->unsubscribe($request, 'thread-unsub');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        self::assertIsArray($data['data']);
        self::assertFalse($data['data']['subscribed']);
    }

    private function createThread(string $id, string $authorId = 'user-001'): Thread
    {
        return Thread::create(
            id: $id,
            categoryId: 'cat-001',
            authorId: $authorId,
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
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
