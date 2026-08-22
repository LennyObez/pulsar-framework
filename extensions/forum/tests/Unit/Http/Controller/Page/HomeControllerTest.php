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
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Http\Controller\Page\HomeController;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(HomeController::class)]
final class HomeControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsJsonWithCategoriesAndRecentThreads(): void
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

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [$thread],
            total: 1,
            hasMore: false,
            perPage: 5,
        ));

        $controller = new HomeController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('categories', $body);
        self::assertArrayHasKey('recent_threads', $body);
        self::assertCount(1, $body['categories']);
        self::assertSame('General Discussion', $body['categories'][0]['name']);
        self::assertSame('Talk about anything', $body['categories'][0]['description']);
        self::assertCount(1, $body['recent_threads']);
        self::assertSame('Test Thread', $body['recent_threads'][0]['title']);
    }

    #[Test]
    public function indexFallsBackToSlugWhenNoTranslation(): void
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

        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findRoots')->willReturn([$category]);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $translationRepo->method('findByCategoryAndLocale')->willReturn(null);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 5,
        ));

        $controller = new HomeController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('general', $body['categories'][0]['name']);
        self::assertSame('', $body['categories'][0]['description']);
    }

    #[Test]
    public function indexUsesLocaleFromQueryParam(): void
    {
        $now = new DateTimeImmutable();

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

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 5,
        ));

        $controller = new HomeController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Accept' => 'application/json'],
            queryParams: ['locale' => 'fr'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Discussion generale', $body['categories'][0]['name']);
    }

    #[Test]
    public function indexWithEmptyCategoriesReturnsEmptyArrays(): void
    {
        $categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $categoryRepo->method('findRoots')->willReturn([]);

        $translationRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);

        $threadRepo = $this->createStub(ThreadRepositoryInterface::class);
        $threadRepo->method('findRecent')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 5,
        ));

        $controller = new HomeController(
            categoryRepository: $categoryRepo,
            translationRepository: $translationRepo,
            threadRepository: $threadRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['categories']);
        self::assertSame([], $body['recent_threads']);
        self::assertSame(0, $body['total_threads']);
    }
}
