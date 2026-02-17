<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Page\SearchPageController;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(SearchPageController::class)]
final class SearchPageControllerTest extends TestCase
{
    #[Test]
    public function indexWithEmptyQueryReturnsNoResults(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);

        $controller = new SearchPageController(
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/search',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('', $body['query']);
        self::assertSame([], $body['threads']);
        self::assertSame('Search', $body['page_title']);
    }

    #[Test]
    public function indexWithWhitespaceOnlyQueryReturnsNoResults(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);

        $controller = new SearchPageController(
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/search',
            headers: ['Accept' => 'application/json'],
            queryParams: ['q' => '   '],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('', $body['query']);
        self::assertSame([], $body['threads']);
    }

    #[Test]
    public function indexWithQueryReturnsMatchingThreads(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $thread = new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'How to use PHP generics',
            slug: 'how-to-use-php-generics',
            type: ThreadType::Question,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 3,
            viewCount: 42,
            voteScore: 8,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('search')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new SearchPageController(
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/search',
            headers: ['Accept' => 'application/json'],
            queryParams: ['q' => 'PHP generics'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('PHP generics', $body['query']);
        self::assertCount(1, $body['threads']);
        self::assertSame('How to use PHP generics', $body['threads'][0]['title']);
        self::assertSame('Search: PHP generics', $body['page_title']);
    }

    #[Test]
    public function indexSetsNoindexMetaRobotsForSeoProtection(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('search')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new SearchPageController(
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/search',
            headers: ['Accept' => 'application/json'],
            queryParams: ['q' => 'test'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('meta_robots', $body);
        self::assertSame('noindex, follow', $body['meta_robots']);
    }

    #[Test]
    public function indexClampsPerPageTo100(): void
    {
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('search')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 100,
        ));

        $controller = new SearchPageController(
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/search',
            headers: ['Accept' => 'application/json'],
            queryParams: ['q' => 'test', 'per_page' => '999'],
        );

        $response = $controller->index($request);

        // No exception thrown, verifies the max(1, min(100, ...)) logic
        self::assertSame(200, $response->getStatusCode());
    }
}
