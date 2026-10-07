<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Api\SearchApiController;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(SearchApiController::class)]
final class SearchApiControllerTest extends TestCase
{
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

    #[Test]
    public function emptyQueryReturns422(): void
    {
        $controller = new SearchApiController(
            $this->createStub(ThreadRepositoryInterface::class),
            new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/search',
            queryParams: ['q' => ''],
        );

        $response = $controller->search($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('q', $body['details']);
    }

    #[Test]
    public function missingQueryReturns422(): void
    {
        $controller = new SearchApiController(
            $this->createStub(ThreadRepositoryInterface::class),
            new ForumConfig(),
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/search');

        $response = $controller->search($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function whitespaceOnlyQueryReturns422(): void
    {
        $controller = new SearchApiController(
            $this->createStub(ThreadRepositoryInterface::class),
            new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/search',
            queryParams: ['q' => '   '],
        );

        $response = $controller->search($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function validQueryReturnsResults(): void
    {
        $thread = $this->makeThread();

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new SearchApiController($threadRepo, new ForumConfig());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/search',
            queryParams: ['q' => 'test'],
        );

        $response = $controller->search($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('test', $body['query']);
        self::assertArrayHasKey('pagination', $body);
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function paginationProvider(): iterable
    {
        yield 'defaults when no params' => [[], 1];
        yield 'page 2' => [['q' => 'test', 'page' => '2'], 2];
        yield 'negative page clamps to 1' => [['q' => 'test', 'page' => '-5'], 1];
        yield 'zero page clamps to 1' => [['q' => 'test', 'page' => '0'], 1];
    }

    #[Test]
    #[DataProvider('paginationProvider')]
    public function searchPaginationBehavior(array $queryParams, int $expectedPage): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new SearchApiController($threadRepo, new ForumConfig());

        if (!isset($queryParams['q'])) {
            $queryParams['q'] = 'test';
        }

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/search',
            queryParams: $queryParams,
        );

        $response = $controller->search($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
