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
use Pulsar\Extension\Forum\Http\Controller\Api\ThreadApiController;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(ThreadApiController::class)]
final class ThreadApiControllerTest extends TestCase
{
    private function makeIdentity(string $id = 'user-1'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn($id);
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeThread(string $authorId = 'user-1'): Thread
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: $authorId,
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

    private function makeController(
        ?ThreadRepositoryInterface $threadRepo = null,
        ?ForumServiceInterface $forumService = null,
        ?GateInterface $gate = null,
    ): ThreadApiController {
        return new ThreadApiController(
            threadRepository: $threadRepo ?? $this->createStub(ThreadRepositoryInterface::class),
            subscriptionRepository: $this->createStub(ThreadSubscriptionRepositoryInterface::class),
            forumService: $forumService ?? $this->createStub(ForumServiceInterface::class),
            markdown: $this->createStub(MarkdownRendererInterface::class),
            bodyPolicy: new ForumBodyPolicy(),
            config: new ForumConfig(),
            gate: $gate,
        );
    }

    #[Test]
    public function indexReturnsRecentThreads(): void
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

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Total-Count'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('Test Thread', $body['data'][0]['title']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function showReturnsThread(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads/thread-1');

        $response = $controller->show($request, 'thread-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('thread-1', $body['data']['id']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/threads/nonexistent');

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function createWithoutAuthThrows(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
        )->withParsedBody(['title' => 'Test', 'slug' => 'test', 'category_id' => 'cat-1']);

        $this->expectException(ForumException::class);

        $controller->create($request);
    }

    #[Test]
    public function createWithInvalidBodyReturns400(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
        )->withAttribute('identity', $this->makeIdentity());
        // No parsed body

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createWithMissingFieldsReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody([]);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('title', $body['details']);
        self::assertArrayHasKey('slug', $body['details']);
        self::assertArrayHasKey('category_id', $body['details']);
    }

    #[Test]
    public function createWithInvalidTypeReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody([
                'title' => 'Test',
                'slug' => 'test',
                'category_id' => 'cat-1',
                'type' => 'invalid_type',
            ]);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function updateReturns404WhenThreadNotFound(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/threads/t1',
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['title' => 'Updated']);

        $response = $controller->update($request, 't1');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateByNonOwnerReturns403(): void
    {
        $thread = $this->makeThread(authorId: 'other-user');

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $controller = $this->makeController(threadRepo: $threadRepo);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/api/v1/forum/threads/thread-1',
        )->withAttribute('identity', $this->makeIdentity('user-1'))
            ->withParsedBody(['title' => 'Updated']);

        $response = $controller->update($request, 'thread-1');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deleteByNonOwnerNonModeratorReturns403(): void
    {
        $thread = $this->makeThread(authorId: 'other-user');

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findById')->willReturn($thread);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(false);

        $controller = $this->makeController(threadRepo: $threadRepo, gate: $gate);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/api/v1/forum/threads/thread-1',
        )->withAttribute('identity', $this->makeIdentity('user-1'));

        $response = $controller->delete($request, 'thread-1');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function lockByNonModeratorReturns403(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/t1/lock',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->lock($request, 't1');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function pinByNonModeratorReturns403(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/v1/forum/threads/t1/pin',
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->pin($request, 't1');

        self::assertSame(403, $response->getStatusCode());
    }
}
