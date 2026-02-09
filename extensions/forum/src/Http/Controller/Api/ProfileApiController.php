<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function max;
use function min;

/**
 * Public REST API controller for forum user profiles.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class ProfileApiController
{
    public function __construct(
        private ForumProfileRepositoryInterface $profileRepository,
        private ThreadRepositoryInterface $threadRepository,
        private PostRepositoryInterface $postRepository,
        private ForumConfig $config,
    ) {}

    /**
     * GET /api/v1/forum/profiles/{userId} — Show a user's forum profile.
     */
    public function show(ServerRequestInterface $request, string $userId): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $profile = $this->profileRepository->findByUser($userId, $tenantId);

        if ($profile === null) {
            return Response::json(['error' => 'Profile not found', 'status' => 404], 404);
        }

        return Response::json(['data' => self::serializeProfile($profile)]);
    }

    /**
     * GET /api/v1/forum/profiles/{userId}/threads — List threads by a user.
     */
    public function threads(ServerRequestInterface $request, string $userId): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

        $result = $this->threadRepository->findByAuthor($userId, $page, $perPage);

        $data = array_map(static fn(Thread $t) => [
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

        return Response::json(['data' => $data, 'pagination' => $result->metaToArray()]);
    }

    /**
     * GET /api/v1/forum/profiles/{userId}/posts — List posts by a user.
     */
    public function posts(ServerRequestInterface $request, string $userId): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->postsPerPage));

        $result = $this->postRepository->findByAuthor($userId, $page, $perPage);

        $data = array_map(static fn(Post $p) => [
            'id' => $p->id,
            'thread_id' => $p->threadId,
            'body' => $p->body,
            'is_solution' => $p->isSolution,
            'vote_score' => $p->voteScore,
            'created_at' => $p->createdAt->format('c'),
        ], $result->items);

        return Response::json(['data' => $data, 'pagination' => $result->metaToArray()]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeProfile(ForumProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->userId,
            'reputation_score' => $profile->reputationScore,
            'reputation_level' => $profile->reputationLevel()->value,
            'post_count' => $profile->postCount,
            'thread_count' => $profile->threadCount,
            'is_banned' => $profile->isBanned,
            'created_at' => $profile->createdAt->format('c'),
        ];
    }
}
