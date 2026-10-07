<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Api\TagApiController;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(TagApiController::class)]
final class TagApiControllerTest extends TestCase
{
    private function makeTag(string $id = 'tag-1', string $slug = 'php'): Tag
    {
        return new Tag(
            id: $id,
            slug: $slug,
            name: 'PHP',
            description: 'PHP programming',
            usageCount: 42,
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

    #[Test]
    public function indexReturnsAllTags(): void
    {
        $tag = $this->makeTag();

        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findAll')->willReturn([$tag]);

        $controller = new TagApiController(
            $tagRepo,
            $this->createStub(ThreadRepositoryInterface::class),
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/tags');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('tag-1', $body['data'][0]['id']);
        self::assertSame('PHP', $body['data'][0]['name']);
        self::assertSame(42, $body['data'][0]['usage_count']);
    }

    #[Test]
    public function indexWithEmptyTags(): void
    {
        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findAll')->willReturn([]);

        $controller = new TagApiController(
            $tagRepo,
            $this->createStub(ThreadRepositoryInterface::class),
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/tags');

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function showReturns404WhenTagNotFound(): void
    {
        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findBySlug')->willReturn(null);

        $controller = new TagApiController(
            $tagRepo,
            $this->createStub(ThreadRepositoryInterface::class),
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/tags/nonexistent');

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsTagWithThreads(): void
    {
        $tag = $this->makeTag();
        $thread = $this->makeThread();

        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findBySlug')->willReturn($tag);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByTag')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new TagApiController($tagRepo, $threadRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/tags/php');

        $response = $controller->show($request, 'php');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tag-1', $body['data']['tag']['id']);
        self::assertSame('PHP', $body['data']['tag']['name']);
        self::assertCount(1, $body['data']['threads']);
        self::assertArrayHasKey('pagination', $body);
    }
}
