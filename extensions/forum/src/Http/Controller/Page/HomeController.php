<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Forum homepage: lists categories with thread counts and recent activity.
 */
#[Internal(reason: 'Forum page controller; implementation detail')]
final readonly class HomeController
{
    use RendersForumView;

    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private CategoryTranslationRepositoryInterface $translationRepository,
        private ThreadRepositoryInterface $threadRepository,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /: Forum homepage with category listing.
     */
    public function index(ServerRequestInterface $request): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $params = $request->getQueryParams();
        $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';

        $categories = $this->categoryRepository->findRoots($tenantId);
        $recentThreads = $this->threadRepository->findRecent(1, 5, $tenantId);

        $categoryData = array_map(
            fn(Category $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name' => $this->getCategoryName($c, $locale),
                'description' => $this->getCategoryDescription($c, $locale),
                'is_locked' => $c->isLocked,
                'sort_order' => $c->sortOrder,
            ],
            $categories,
        );

        $recentData = array_map(static fn(Thread $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'slug' => $t->slug,
            'status' => $t->status->value,
            'reply_count' => $t->replyCount,
            'view_count' => $t->viewCount,
            'vote_score' => $t->voteScore,
            'is_pinned' => $t->isPinned,
            'created_at' => $t->createdAt->format('c'),
            'last_activity_at' => $t->lastActivityAt?->format('c'),
        ], $recentThreads->items);

        return $this->respondWithView($request, 'forum.home', [
            'categories' => $categoryData,
            'recent_threads' => $recentData,
            'total_threads' => $recentThreads->total,
            'page_title' => 'Forum',
        ]);
    }

    private function getCategoryName(Category $category, string $locale): string
    {
        $translation = $this->translationRepository->findByCategoryAndLocale($category->id, $locale);

        return $translation !== null ? $translation->name : $category->slug;
    }

    private function getCategoryDescription(Category $category, string $locale): string
    {
        $translation = $this->translationRepository->findByCategoryAndLocale($category->id, $locale);

        return $translation !== null ? $translation->description : '';
    }
}
