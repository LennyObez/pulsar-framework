<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;
use function max;
use function min;

/**
 * Category page: lists threads within a category, sorted by latest activity.
 */
#[Internal(reason: 'Forum page controller; implementation detail')]
final readonly class CategoryPageController
{
    use RendersForumView;

    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private CategoryTranslationRepositoryInterface $translationRepository,
        private ThreadRepositoryInterface $threadRepository,
        private ForumConfig $config,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /c/{slug}: List threads in a category.
     */
    public function show(ServerRequestInterface $request, string $slug): Response
    {
        $category = $this->categoryRepository->findBySlug($slug);

        if ($category === null) {
            return $this->respondWithView($request, 'forum.404', [
                'page_title' => 'Category Not Found',
                'message' => 'The category you are looking for does not exist.',
            ], 404);
        }

        $params = $request->getQueryParams();
        $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

        $result = $this->threadRepository->findByCategory($category->id, $page, $perPage);

        $translation = $this->translationRepository->findByCategoryAndLocale($category->id, $locale);
        $categoryName = $translation !== null ? $translation->name : $category->slug;
        $categoryDescription = $translation !== null ? $translation->description : '';

        $threads = array_map(static fn(Thread $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'slug' => $t->slug,
            'author_id' => $t->authorId,
            'status' => $t->status->value,
            'type' => $t->type->value,
            'is_pinned' => $t->isPinned,
            'is_locked' => $t->isLocked,
            'is_solved' => $t->solvedPostId !== null,
            'reply_count' => $t->replyCount,
            'view_count' => $t->viewCount,
            'vote_score' => $t->voteScore,
            'created_at' => $t->createdAt->format('c'),
            'last_activity_at' => $t->lastActivityAt?->format('c'),
        ], $result->items);

        return $this->respondWithView($request, 'forum.category', [
            'category' => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $categoryName,
                'description' => $categoryDescription,
                'is_locked' => $category->isLocked,
            ],
            'threads' => $threads,
            'pagination' => $result->metaToArray(),
            'page' => $page,
            'page_title' => $categoryName,
        ]);
    }
}
