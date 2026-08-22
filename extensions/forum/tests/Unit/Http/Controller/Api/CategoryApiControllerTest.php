<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Api\CategoryApiController;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CategoryApiController::class)]
final class CategoryApiControllerTest extends TestCase
{
    private function makeCategory(string $id = 'cat-1', ?string $parentId = null): Category
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        return new Category(
            id: $id,
            tenantId: null,
            parentId: $parentId,
            slug: 'general',
            sortOrder: 0,
            isLocked: false,
            createdAt: $now,
            updatedAt: $now,
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

    private function makeController(
        ?CategoryRepositoryInterface $categoryRepo = null,
        ?CategoryTranslationRepositoryInterface $translationRepo = null,
        ?ThreadRepositoryInterface $threadRepo = null,
    ): CategoryApiController {
        return new CategoryApiController(
            categoryRepository: $categoryRepo ?? $this->createStub(CategoryRepositoryInterface::class),
            translationRepository: $translationRepo ?? $this->createStub(CategoryTranslationRepositoryInterface::class),
            threadRepository: $threadRepo ?? $this->createStub(ThreadRepositoryInterface::class),
            config: new ForumConfig(),
        );
    }

    #[Test]
    public function indexReturnsCategories(): void
    {
        $category = $this->makeCategory();

        $translation = new CategoryTranslation(
            id: 'trans-1',
            categoryId: 'cat-1',
            locale: 'en',
            name: 'General Discussion',
            description: 'Talk about anything',
        );

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findRoots')->willReturn([$category]);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn($translation);

        $controller = $this->makeController(categoryRepo: $categoryRepo, translationRepo: $translationRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/categories');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('cat-1', $body['data'][0]['id']);
        self::assertSame('General Discussion', $body['data'][0]['name']);
        self::assertSame('Talk about anything', $body['data'][0]['description']);
    }

    #[Test]
    public function indexFallsBackToSlugWhenNoTranslation(): void
    {
        $category = $this->makeCategory();

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findRoots')->willReturn([$category]);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn(null);

        $controller = $this->makeController(categoryRepo: $categoryRepo, translationRepo: $translationRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/categories');

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('general', $body['data'][0]['name']);
        self::assertSame('', $body['data'][0]['description']);
    }

    #[Test]
    public function showReturnsCategory(): void
    {
        $category = $this->makeCategory();
        $child = $this->makeCategory('cat-2', 'cat-1');

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findById')->willReturn($category);
        $categoryRepo->method('findByParent')->willReturn([$child]);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn(null);

        $controller = $this->makeController(categoryRepo: $categoryRepo, translationRepo: $translationRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/categories/cat-1');

        $response = $controller->show($request, 'cat-1');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cat-1', $body['data']['category']['id']);
        self::assertCount(1, $body['data']['children']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(categoryRepo: $categoryRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/categories/nonexistent');

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function threadsReturns404WhenCategoryNotFound(): void
    {
        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(categoryRepo: $categoryRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/categories/nonexistent/threads');

        $response = $controller->threads($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function threadsReturnsPaginatedThreads(): void
    {
        $category = $this->makeCategory();
        $thread = $this->makeThread();

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findById')->willReturn($category);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByCategory')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = $this->makeController(categoryRepo: $categoryRepo, threadRepo: $threadRepo);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/forum/categories/cat-1/threads');

        $response = $controller->threads($request, 'cat-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Total-Count'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('thread-1', $body['data'][0]['id']);
        self::assertArrayHasKey('pagination', $body);
    }

    #[Test]
    public function indexUsesLocaleParam(): void
    {
        $category = $this->makeCategory();

        $frTranslation = new CategoryTranslation(
            id: 'trans-fr',
            categoryId: 'cat-1',
            locale: 'fr',
            name: 'Discussion generale',
            description: 'Parlez de tout',
        );

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findRoots')->willReturn([$category]);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn($frTranslation);

        $controller = $this->makeController(categoryRepo: $categoryRepo, translationRepo: $translationRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/forum/categories',
            queryParams: ['locale' => 'fr'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Discussion generale', $body['data'][0]['name']);
    }
}
