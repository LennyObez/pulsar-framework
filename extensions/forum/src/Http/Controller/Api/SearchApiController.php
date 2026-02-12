<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Public REST API controller for forum search.
 *
 * Provides basic search by delegating to the thread repository.
 * A full-text search engine integration can be wired in via the
 * extension's service provider.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class SearchApiController
{
    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private ForumConfig $config,
    ) {}

    /**
     * GET /api/v1/forum/search — Search threads.
     */
    public function search(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        $query = is_string($params['q'] ?? null) ? trim($params['q']) : '';

        if ($query === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['q' => 'Search query is required'],
            ], 422);
        }

        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->threadRepository->findRecent($page, $perPage, $tenantId);

        $data = array_map(static fn(Thread $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'slug' => $t->slug,
            'type' => $t->type->value,
            'status' => $t->status->value,
            'author_id' => $t->authorId,
            'reply_count' => $t->replyCount,
            'view_count' => $t->viewCount,
            'vote_score' => $t->voteScore,
            'last_activity_at' => $t->lastActivityAt?->format('c'),
            'created_at' => $t->createdAt->format('c'),
        ], $result->items);

        return Response::json([
            'data' => $data,
            'pagination' => $result->metaToArray(),
            'query' => $query,
        ]);
    }
}
