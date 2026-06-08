<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for forum categories.
 */
#[Internal(reason: 'Forum REST API controller; implementation detail')]
final readonly class CategoryApiController
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private CategoryTranslationRepositoryInterface $translationRepository,
        private ThreadRepositoryInterface $threadRepository,
        private ForumConfig $config,
    ) {}

    /**
     * GET /api/v1/forum/categories: List root categories.
     */
    public function index(ServerRequestInterface $request): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $categories = $this->categoryRepository->findRoots($tenantId);
        $params = $request->getQueryParams();
        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : 'en';

        $data = array_map(
            fn(Category $c) => $this->serializeCategory($c, $locale),
            $categories,
        );

        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/v1/forum/categories/{id}: Show a single category with children.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawLocale */
        $rawLocale = $params['locale'] ?? null;
        $locale = is_string($rawLocale) ? $rawLocale : 'en';

        $children = $this->categoryRepository->findByParent($id);

        return Response::json([
            'data' => [
                'category' => $this->serializeCategory($category, $locale),
                'children' => array_map(
                    fn(Category $c) => $this->serializeCategory($c, $locale),
                    $children,
                ),
            ],
        ]);
    }

    /**
     * GET /api/v1/forum/categories/{id}/threads: List threads in a category.
     */
    public function threads(ServerRequestInterface $request, string $id): Response
    {
        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, (is_int($rawPage) || is_string($rawPage)) && is_numeric($rawPage) ? (int) $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, (is_int($rawPerPage) || is_string($rawPerPage)) && is_numeric($rawPerPage) ? (int) $rawPerPage : $this->config->threadsPerPage));

        /** @var mixed $rawStatus */
        $rawStatus = $params['status'] ?? null;
        $status = is_string($rawStatus) ? ThreadStatus::tryFrom($rawStatus) : null;
        /** @var mixed $rawType */
        $rawType = $params['type'] ?? null;
        $type = is_string($rawType) ? ThreadType::tryFrom($rawType) : null;

        $result = $this->threadRepository->findByCategory($id, $page, $perPage, $status, $type);

        $data = array_map(self::serializeThread(...), $result->items);

        return Response::json(['data' => $data, 'pagination' => $result->metaToArray()])
            ->withHeader('X-Total-Count', (string) $result->total)
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCategory(Category $category, string $locale): array
    {
        $translation = $this->translationRepository->findByCategoryAndLocale($category->id, $locale);

        return [
            'id' => $category->id,
            'parent_id' => $category->parentId,
            'slug' => $category->slug,
            'sort_order' => $category->sortOrder,
            'is_locked' => $category->isLocked,
            'name' => $translation !== null ? $translation->name : $category->slug,
            'description' => $translation !== null ? $translation->description : '',
            'created_at' => $category->createdAt->format('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeThread(Thread $thread): array
    {
        return [
            'id' => $thread->id,
            'category_id' => $thread->categoryId,
            'author_id' => $thread->authorId,
            'title' => $thread->title,
            'slug' => $thread->slug,
            'type' => $thread->type->value,
            'status' => $thread->status->value,
            'is_pinned' => $thread->isPinned,
            'is_locked' => $thread->isLocked,
            'reply_count' => $thread->replyCount,
            'view_count' => $thread->viewCount,
            'vote_score' => $thread->voteScore,
            'last_activity_at' => $thread->lastActivityAt?->format('c'),
            'created_at' => $thread->createdAt->format('c'),
        ];
    }
}
