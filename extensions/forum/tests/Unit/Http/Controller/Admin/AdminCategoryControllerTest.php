<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Http\Controller\Admin\CategoryController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CategoryController::class)]
final class AdminCategoryControllerTest extends TestCase
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

    private function makeCategory(
        string $id = 'cat-1',
        string $slug = 'general',
        ?string $parentId = null,
        bool $isLocked = false,
    ): Category {
        $now = new DateTimeImmutable('2025-06-01 10:00:00');

        return new Category(
            id: $id,
            tenantId: null,
            parentId: $parentId,
            slug: $slug,
            sortOrder: 0,
            isLocked: $isLocked,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeTranslation(
        string $categoryId = 'cat-1',
        string $locale = 'en',
        string $name = 'General',
        string $description = 'General discussion',
    ): CategoryTranslation {
        return new CategoryTranslation(
            id: 'trans-1',
            categoryId: $categoryId,
            locale: $locale,
            name: $name,
            description: $description,
        );
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?CategoryRepositoryInterface $catRepo = null,
        ?CategoryTranslationRepositoryInterface $transRepo = null,
    ): CategoryController {
        if ($transRepo === null) {
            $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
            $transRepo->method('findByCategory')->willReturn([]);
        }

        return new CategoryController(
            categoryRepository: $catRepo ?? $this->createStub(CategoryRepositoryInterface::class),
            translationRepository: $transRepo,
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    private function makeRequest(
        string $method = 'GET',
        string $uri = '/admin/forum/categories',
        ?array $parsedBody = null,
    ): ServerRequest {
        $request = new ServerRequest(
            method: $method,
            uri: $uri,
            headers: ['Accept' => 'application/json'],
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
    public function indexReturnsCategories(): void
    {
        $category = $this->makeCategory();
        $translation = $this->makeTranslation();

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findRoots')->willReturn([$category]);

        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([$translation]);

        $controller = $this->makeController(catRepo: $catRepo, transRepo: $transRepo);

        $response = $controller->index($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('cat-1', $body['data'][0]['id']);
        self::assertSame('general', $body['data'][0]['slug']);
        self::assertCount(1, $body['data'][0]['translations']);
        self::assertSame('General', $body['data'][0]['translations'][0]['name']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(catRepo: $catRepo);

        $response = $controller->show($this->makeRequest(), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsCategoryWithChildren(): void
    {
        $parent = $this->makeCategory();
        $child = $this->makeCategory(id: 'cat-2', slug: 'php', parentId: 'cat-1');

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn($parent);
        $catRepo->method('findByParent')->willReturn([$child]);

        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([]);

        $controller = $this->makeController(catRepo: $catRepo, transRepo: $transRepo);

        $response = $controller->show($this->makeRequest(), 'cat-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cat-1', $body['category']['id']);
        self::assertCount(1, $body['children']);
        self::assertSame('cat-2', $body['children'][0]['id']);
    }

    #[Test]
    public function createWithEmptySlugReturns422(): void
    {
        $controller = $this->makeController();

        $response = $controller->create(
            $this->makeRequest('POST', parsedBody: ['slug' => '']),
        );

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Slug is required', $body['error']);
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([]);

        $controller = $this->makeController(transRepo: $transRepo);

        $response = $controller->create(
            $this->makeRequest('POST', parsedBody: [
                'slug' => 'php',
                'name' => 'PHP',
                'description' => 'PHP discussion',
                'locale' => 'en',
            ]),
        );

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('php', $body['data']['slug']);
        self::assertFalse($body['data']['is_locked']);
    }

    #[Test]
    public function updateReturns404WhenNotFound(): void
    {
        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(catRepo: $catRepo);

        $response = $controller->update(
            $this->makeRequest('PUT', parsedBody: ['slug' => 'new-slug']),
            'nonexistent',
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function updateChangesSlug(): void
    {
        $category = $this->makeCategory();

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn($category);

        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([]);

        $controller = $this->makeController(catRepo: $catRepo, transRepo: $transRepo);

        $response = $controller->update(
            $this->makeRequest('PUT', parsedBody: ['slug' => 'new-general']),
            'cat-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('new-general', $body['data']['slug']);
    }

    #[Test]
    public function updateLocksCategory(): void
    {
        $category = $this->makeCategory();

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn($category);

        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([]);

        $controller = $this->makeController(catRepo: $catRepo, transRepo: $transRepo);

        $response = $controller->update(
            $this->makeRequest('PUT', parsedBody: ['is_locked' => true]),
            'cat-1',
        );

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['is_locked']);
    }

    #[Test]
    public function updateTranslationCreatesNew(): void
    {
        $category = $this->makeCategory();

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn($category);

        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([]);
        $transRepo->method('findByCategoryAndLocale')->willReturn(null);

        $controller = $this->makeController(catRepo: $catRepo, transRepo: $transRepo);

        $response = $controller->update(
            $this->makeRequest('PUT', parsedBody: [
                'name' => 'Allgemein',
                'description' => 'Allgemeine Diskussion',
                'locale' => 'de',
            ]),
            'cat-1',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function updateTranslationUpdatesExisting(): void
    {
        $category = $this->makeCategory();
        $translation = $this->makeTranslation();

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn($category);

        $transRepo = $this->createStub(CategoryTranslationRepositoryInterface::class);
        $transRepo->method('findByCategory')->willReturn([$translation]);
        $transRepo->method('findByCategoryAndLocale')->willReturn($translation);

        $controller = $this->makeController(catRepo: $catRepo, transRepo: $transRepo);

        $response = $controller->update(
            $this->makeRequest('PUT', parsedBody: [
                'name' => 'Updated General',
                'locale' => 'en',
            ]),
            'cat-1',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturns404WhenNotFound(): void
    {
        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(catRepo: $catRepo);

        $response = $controller->delete($this->makeRequest('DELETE'), 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccess(): void
    {
        $category = $this->makeCategory();

        $catRepo = $this->createStub(CategoryRepositoryInterface::class);
        $catRepo->method('findById')->willReturn($category);

        $controller = $this->makeController(catRepo: $catRepo);

        $response = $controller->delete($this->makeRequest('DELETE'), 'cat-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('deleted', $body['data']['status']);
        self::assertSame('cat-1', $body['data']['id']);
    }
}
