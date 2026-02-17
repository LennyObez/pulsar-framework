<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Page;

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
use Pulsar\Extension\Forum\Http\Controller\Page\CategoryPageController;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CategoryPageController::class)]
final class CategoryPageControllerTest extends TestCase
{
    #[Test]
    public function showReturns404WhenCategoryNotFound(): void
    {
        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findBySlug')->willReturn(null);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);

        $controller = new CategoryPageController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/c/nonexistent',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Category Not Found', $body['page_title']);
    }

    #[Test]
    public function showReturnsCategoryWithThreads(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $category = new Category(
            id: 'cat-1',
            tenantId: null,
            parentId: null,
            slug: 'general',
            sortOrder: 0,
            isLocked: false,
            createdAt: $now,
            updatedAt: $now,
        );

        $thread = new Thread(
            id: 'thread-1',
            tenantId: null,
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'First Thread',
            slug: 'first-thread',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 2,
            viewCount: 30,
            voteScore: 5,
            lastActivityAt: $now,
            ipHash: 'abc',
            userAgentHash: 'def',
            createdAt: $now,
            updatedAt: $now,
            deletedAt: null,
        );

        $translation = new CategoryTranslation(
            id: 'trans-1',
            categoryId: 'cat-1',
            locale: 'en',
            name: 'General Discussion',
            description: 'General topics',
        );

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findBySlug')->willReturn($category);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn($translation);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByCategory')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new CategoryPageController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/c/general',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'general');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('General Discussion', $body['category']['name']);
        self::assertSame('General topics', $body['category']['description']);
        self::assertCount(1, $body['threads']);
        self::assertSame('First Thread', $body['threads'][0]['title']);
        self::assertSame('General Discussion', $body['page_title']);
    }

    #[Test]
    public function showFallsBackToSlugWhenNoTranslation(): void
    {
        $now = new DateTimeImmutable();

        $category = new Category(
            id: 'cat-1',
            tenantId: null,
            parentId: null,
            slug: 'tech',
            sortOrder: 0,
            isLocked: true,
            createdAt: $now,
            updatedAt: $now,
        );

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findBySlug')->willReturn($category);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn(null);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findByCategory')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 25,
        ));

        $controller = new CategoryPageController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
            config: new ForumConfig(),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/c/tech',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request, 'tech');

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tech', $body['category']['name']);
        self::assertSame('', $body['category']['description']);
        self::assertTrue($body['category']['is_locked']);
    }
}
