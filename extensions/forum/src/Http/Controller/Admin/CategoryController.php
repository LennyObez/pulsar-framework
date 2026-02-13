<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Admin controller for forum category CRUD with translation management.
 */
#[Internal(reason: 'Forum admin controller — implementation detail')]
final readonly class CategoryController
{
    use RendersAdminView;

    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private CategoryTranslationRepositoryInterface $translationRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/categories — List all categories.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.categories');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $categories = $this->categoryRepository->findRoots($tenantId);

        $data = [
            'data' => array_map(fn(Category $c) => $this->serializeWithTranslations($c), $categories),
        ];

        return $this->respondWithView($request, 'admin.forum.categories.index', $data);
    }

    /**
     * GET /admin/forum/categories/{id} — Show a single category with translations.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.categories');

        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found'], 404);
        }

        $children = $this->categoryRepository->findByParent($id);

        $data = [
            'category' => $this->serializeWithTranslations($category),
            'children' => array_map(fn(Category $c) => $this->serializeWithTranslations($c), $children),
        ];

        return $this->respondWithView($request, 'admin.forum.categories.show', $data);
    }

    /**
     * POST /admin/forum/categories — Create a new category.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.categories.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';

        if ($slug === '') {
            return Response::json(['error' => 'Slug is required'], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');
        $parentId = is_string($body['parent_id'] ?? null) ? $body['parent_id'] : null;
        $sortOrder = is_numeric($body['sort_order'] ?? null) ? (int) $body['sort_order'] : 0;

        $category = Category::create(
            id: UuidGenerator::v7(),
            slug: $slug,
            tenantId: $tenantId,
            parentId: $parentId,
            sortOrder: $sortOrder,
        );

        $this->categoryRepository->save($category);

        $name = is_string($body['name'] ?? null) ? $body['name'] : $slug;
        $description = is_string($body['description'] ?? null) ? $body['description'] : '';
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : 'en';

        $translation = CategoryTranslation::create(
            id: UuidGenerator::v7(),
            categoryId: $category->id,
            locale: $locale,
            name: $name,
            description: $description,
        );

        $this->translationRepository->save($translation);

        return Response::json([
            'data' => $this->serializeWithTranslations($category),
        ], 201);
    }

    /**
     * PUT /admin/forum/categories/{id} — Update a category.
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.categories.update');

        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        if (is_string($body['slug'] ?? null) && $body['slug'] !== $category->slug) {
            $category = $category->changeSlug($body['slug']);
        }

        if (isset($body['sort_order'])) {
            $category = $category->reorder(is_numeric($body['sort_order']) ? (int) $body['sort_order'] : 0);
        }

        if (isset($body['is_locked'])) {
            $category = $body['is_locked'] ? $category->lock() : $category->unlock();
        }

        $this->categoryRepository->save($category);

        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : 'en';

        if (is_string($body['name'] ?? null) || is_string($body['description'] ?? null)) {
            $translation = $this->translationRepository->findByCategoryAndLocale($id, $locale);

            if ($translation !== null) {
                $name = is_string($body['name'] ?? null) ? $body['name'] : $translation->name;
                $description = is_string($body['description'] ?? null) ? $body['description'] : $translation->description;
                $translation = $translation->update($name, $description);
            } else {
                $translation = CategoryTranslation::create(
                    id: UuidGenerator::v7(),
                    categoryId: $id,
                    locale: $locale,
                    name: is_string($body['name'] ?? null) ? $body['name'] : $category->slug,
                    description: is_string($body['description'] ?? null) ? $body['description'] : '',
                );
            }

            $this->translationRepository->save($translation);
        }

        return Response::json(['data' => $this->serializeWithTranslations($category)]);
    }

    /**
     * DELETE /admin/forum/categories/{id} — Delete a category.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.categories.delete');

        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found'], 404);
        }

        $this->categoryRepository->delete($category);

        return Response::json(['data' => ['id' => $id, 'status' => 'deleted']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWithTranslations(Category $category): array
    {
        $translations = $this->translationRepository->findByCategory($category->id);

        return [
            'id' => $category->id,
            'parent_id' => $category->parentId,
            'slug' => $category->slug,
            'sort_order' => $category->sortOrder,
            'is_locked' => $category->isLocked,
            'created_at' => $category->createdAt->format('c'),
            'updated_at' => $category->updatedAt->format('c'),
            'translations' => array_map(static fn(CategoryTranslation $t) => [
                'id' => $t->id,
                'locale' => $t->locale,
                'name' => $t->name,
                'description' => $t->description,
            ], $translations),
        ];
    }
}
