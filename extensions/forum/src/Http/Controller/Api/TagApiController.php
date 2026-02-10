<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function max;
use function min;

/**
 * Public REST API controller for forum tags.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class TagApiController
{
    public function __construct(
        private TagRepositoryInterface $tagRepository,
        private ThreadRepositoryInterface $threadRepository,
    ) {}

    /**
     * GET /api/v1/forum/tags — List all tags ordered by usage.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $tags = $this->tagRepository->findAll();

        $data = array_map(static fn(Tag $t) => [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->name,
            'description' => $t->description,
            'usage_count' => $t->usageCount,
        ], $tags);

        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/v1/forum/tags/{slug} — Show a tag and its threads.
     */
    public function show(ServerRequestInterface $request, string $slug): Response
    {
        $tag = $this->tagRepository->findBySlug($slug);

        if ($tag === null) {
            return Response::json(['error' => 'Tag not found', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : 25));

        $result = $this->threadRepository->findByTag($tag->id, $page, $perPage);

        $threads = array_map(static fn(Thread $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'slug' => $t->slug,
            'type' => $t->type->value,
            'status' => $t->status->value,
            'reply_count' => $t->replyCount,
            'view_count' => $t->viewCount,
            'vote_score' => $t->voteScore,
            'created_at' => $t->createdAt->format('c'),
        ], $result->items);

        return Response::json([
            'data' => [
                'tag' => [
                    'id' => $tag->id,
                    'slug' => $tag->slug,
                    'name' => $tag->name,
                    'description' => $tag->description,
                    'usage_count' => $tag->usageCount,
                ],
                'threads' => $threads,
            ],
            'pagination' => $result->metaToArray(),
        ]);
    }
}
