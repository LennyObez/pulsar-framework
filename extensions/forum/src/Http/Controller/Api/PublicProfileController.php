<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function array_slice;
use function count;
use function max;
use function min;
use function usort;

/**
 * Public REST API controller for extended forum user profiles.
 *
 * Provides public-facing profile information including badges, reputation,
 * and recent activity timelines. Private data (ban details, IP hashes) is
 * excluded from the response.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class PublicProfileController
{
    public function __construct(
        private ForumProfileRepositoryInterface $profileRepository,
        private UserBadgeRepositoryInterface $badgeRepository,
        private ThreadRepositoryInterface $threadRepository,
        private PostRepositoryInterface $postRepository,
    ) {}

    /**
     * GET /api/v1/forum/users/{userId} — Extended public profile with badges and stats.
     */
    public function show(ServerRequestInterface $request, string $userId): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $profile = $this->profileRepository->findByUser($userId, $tenantId);

        if ($profile === null) {
            return Response::json(['error' => 'Profile not found', 'status' => 404], 404);
        }

        $badges = $this->badgeRepository->findByUser($userId, $tenantId);

        return Response::json([
            'data' => [
                'profile' => self::serializeProfile($profile),
                'badges' => array_map(static fn(UserBadge $b) => [
                    'badge' => $b->badge->value,
                    'label' => $b->badge->label(),
                    'awarded_at' => $b->awardedAt->format('c'),
                ], $badges),
                'stats' => [
                    'total_posts' => $profile->postCount,
                    'total_threads' => $profile->threadCount,
                    'reputation_score' => $profile->reputationScore,
                    'reputation_level' => $profile->reputationLevel()->label(),
                    'badge_count' => count($badges),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/forum/users/{userId}/activity — Recent activity timeline.
     */
    public function activity(ServerRequestInterface $request, string $userId): Response
    {
        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $profile = $this->profileRepository->findByUser($userId, $tenantId);

        if ($profile === null) {
            return Response::json(['error' => 'Profile not found', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(50, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : 20));

        // Fetch recent threads and posts
        $threads = $this->threadRepository->findByAuthor($userId, 1, $perPage);
        $posts = $this->postRepository->findByAuthor($userId, 1, $perPage);
        $badges = $this->badgeRepository->findByUser($userId, $tenantId);

        // Build unified timeline
        $timeline = self::buildTimeline($threads->items, $posts->items, $badges);

        // Sort by date descending
        usort($timeline, static fn(array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);

        // Paginate the merged timeline
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($timeline, $offset, $perPage);
        $total = count($timeline);

        return Response::json([
            'data' => $paged,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'has_more' => ($offset + $perPage) < $total,
            ],
        ]);
    }

    /**
     * @param list<Thread> $threads
     * @param list<Post> $posts
     * @param list<UserBadge> $badges
     *
     * @return list<array<string, mixed>>
     */
    private static function buildTimeline(array $threads, array $posts, array $badges): array
    {
        $timeline = [];

        foreach ($threads as $thread) {
            $timeline[] = [
                'type' => 'thread',
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'thread_type' => $thread->type->value,
                'status' => $thread->status->value,
                'reply_count' => $thread->replyCount,
                'vote_score' => $thread->voteScore,
                'timestamp' => $thread->createdAt->format('c'),
            ];
        }

        foreach ($posts as $post) {
            $timeline[] = [
                'type' => 'reply',
                'id' => $post->id,
                'thread_id' => $post->threadId,
                'body_preview' => self::truncate($post->body, 200),
                'is_solution' => $post->isSolution,
                'vote_score' => $post->voteScore,
                'timestamp' => $post->createdAt->format('c'),
            ];
        }

        foreach ($badges as $badge) {
            $timeline[] = [
                'type' => 'badge',
                'badge' => $badge->badge->value,
                'label' => $badge->badge->label(),
                'timestamp' => $badge->awardedAt->format('c'),
            ];
        }

        return $timeline;
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
            'reputation_label' => $profile->reputationLevel()->label(),
            'post_count' => $profile->postCount,
            'thread_count' => $profile->threadCount,
            'member_since' => $profile->createdAt->format('c'),
        ];
    }

    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '...';
    }
}
