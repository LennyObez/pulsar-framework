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
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Http\Controller\Admin\ThreadController;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ThreadController::class)]
final class AdminThreadControllerTest extends TestCase
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

    private function makeThread(
        string $id = 'thread-1',
        bool $isPinned = false,
        bool $isLocked = false,
        string $categoryId = 'cat-1',
    ): Thread {
        $now = new DateTimeImmutable('2025-06-01 10:00:00');

        return new Thread(
            id: $id,
            tenantId: null,
            categoryId: $categoryId,
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: $isPinned,
            isLocked: $isLocked,
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

    private function makeController(
        ?GateInterface $gate = null,
        ?ThreadRepositoryInterface $threadRepo = null,
        ?ForumServiceInterface $forumService = null,
    ): ThreadController {
        return new ThreadController(
            threadRepository: $threadRepo ?? $this->createStub(ThreadRepositoryInterface::class),
            forumService: $forumService ?? $this->createStub(ForumServiceInterface::class),
            config: new ForumConfig(),
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        string $uri = '/admin/forum/threads',
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

        $controller->index($this->makeRequest());
    }

    #[Test]
    public function indexReturnsPaginatedThreads(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->index($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('thread-1', $body['data'][0]['id']);
        self::assertSame('Test Thread', $body['data'][0]['title']);
        self::assertSame('discussion', $body['data'][0]['type']);
        self::assertSame('open', $body['data'][0]['status']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->show($this->makeRequest(), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsThread(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->show($this->makeRequest(), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('thread-1', $body['thread']['id']);
        self::assertSame(5, $body['thread']['reply_count']);
        self::assertSame(100, $body['thread']['view_count']);
    }

    #[Test]
    public function lockReturnsLockedThread(): void
    {
        $lockedThread = $this->makeThread(isLocked: true);

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('lockThread')->willReturn($lockedThread);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->lock($this->makeRequest('POST'), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['is_locked']);
    }

    #[Test]
    public function lockServiceExceptionReturns422(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('lockThread')->willThrowException(
            new ForumException('Thread not found'),
        );

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->lock($this->makeRequest('POST'), 'bad-id');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function unlockReturnsUnlockedThread(): void
    {
        $thread = $this->makeThread(isLocked: false);

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('unlockThread')->willReturn($thread);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->unlock($this->makeRequest('POST'), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['data']['is_locked']);
    }

    #[Test]
    public function pinReturnsPinnedThread(): void
    {
        $pinnedThread = $this->makeThread(isPinned: true);

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('pinThread')->willReturn($pinnedThread);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->pin($this->makeRequest('POST'), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['is_pinned']);
    }

    #[Test]
    public function unpinReturnsUnpinnedThread(): void
    {
        $thread = $this->makeThread(isPinned: false);

        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('unpinThread')->willReturn($thread);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->unpin($this->makeRequest('POST'), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['data']['is_pinned']);
    }

    #[Test]
    public function moveReturns404WhenThreadNotFound(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->move(
            $this->makeRequest('POST', '/admin/forum/threads/bad/move', parsedBody: ['category_id' => 'cat-2']),
            'bad',
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function moveWithoutCategoryIdReturns422(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->move(
            $this->makeRequest('POST', '/admin/forum/threads/thread-1/move', parsedBody: ['category_id' => '']),
            'thread-1',
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Category ID is required', $body['error']);
    }

    #[Test]
    public function moveReturnsMovedThread(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $response = $controller->move(
            $this->makeRequest('POST', '/admin/forum/threads/thread-1/move', parsedBody: ['category_id' => 'cat-2']),
            'thread-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cat-2', $body['data']['category_id']);
    }

    #[Test]
    public function deleteReturnsSuccess(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->delete($this->makeRequest('DELETE'), 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('deleted', $body['data']['status']);
        self::assertSame('thread-1', $body['data']['id']);
    }

    #[Test]
    public function deleteServiceExceptionReturns422(): void
    {
        $forumService = $this->createStub(ForumServiceInterface::class);
        $forumService->method('deleteThread')->willThrowException(
            new ForumException('Thread not found'),
        );

        $controller = $this->makeController(forumService: $forumService);

        $response = $controller->delete($this->makeRequest('DELETE'), 'bad-id');

        self::assertSame(422, $response->getStatusCode());
    }
}
