<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Search page: full-text search across threads and posts.
 */
#[Internal(reason: 'Forum page controller; implementation detail')]
final readonly class SearchPageController
{
    use RendersForumView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private ForumConfig $config,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /search: Search threads.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawQuery */
        $rawQuery = $params['q'] ?? null;
        $query = is_string($rawQuery) ? trim($rawQuery) : '';
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_numeric($rawPage) ? (int) $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_numeric($rawPerPage) ? (int) $rawPerPage : $this->config->threadsPerPage));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $threads = [];
        $pagination = ['current_page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1];

        if ($query !== '') {
            $result = $this->threadRepository->search($query, $page, $perPage, $tenantId);
            $threads = array_map(static fn(Thread $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'slug' => $t->slug,
                'status' => $t->status->value,
                'reply_count' => $t->replyCount,
                'view_count' => $t->viewCount,
                'vote_score' => $t->voteScore,
                'created_at' => $t->createdAt->format('c'),
            ], $result->items);
            $pagination = $result->metaToArray();
        }

        return $this->respondWithView($request, 'forum.search', [
            'query' => $query,
            'threads' => $threads,
            'pagination' => $pagination,
            'page' => $page,
            'page_title' => $query !== '' ? 'Search: ' . $query : 'Search',
            'meta_robots' => 'noindex, follow',
        ]);
    }
}
