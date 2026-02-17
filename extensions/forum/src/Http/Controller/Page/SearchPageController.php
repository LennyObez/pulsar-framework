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
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $query = is_string($params['q'] ?? null) ? trim($params['q']) : '';
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

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
