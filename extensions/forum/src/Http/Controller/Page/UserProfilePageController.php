<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;

/**
 * Public user profile page: post history, reputation, and badges.
 */
#[Internal(reason: 'Forum page controller; implementation detail')]
final readonly class UserProfilePageController
{
    use RendersForumView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ForumProfileRepositoryInterface $profileRepository,
        private ThreadRepositoryInterface $threadRepository,
        private PostRepositoryInterface $postRepository,
        private UserBadgeRepositoryInterface $badgeRepository,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /u/{userId}: Show public user profile.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request, string $userId): Response
    {
        $profile = $this->profileRepository->findByUser($userId);

        if ($profile === null) {
            return $this->respondWithView($request, 'forum.404', [
                'page_title' => 'User Not Found',
                'message' => 'The user profile you are looking for does not exist.',
            ], 404);
        }

        $recentThreads = $this->threadRepository->findByAuthor($userId, 1, 10);
        $recentPosts = $this->postRepository->findByAuthor($userId, 1, 10);
        $badges = $this->badgeRepository->findByUser($userId);

        return $this->respondWithView($request, 'forum.profile', [
            'profile' => [
                'user_id' => $profile->userId,
                'reputation_score' => $profile->reputationScore,
                'reputation_level' => $profile->reputationLevel()->value,
                'post_count' => $profile->postCount,
                'thread_count' => $profile->threadCount,
                'is_banned' => $profile->isBanned,
                'created_at' => $profile->createdAt->format('c'),
            ],
            'threads' => array_map(static fn(Thread $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'slug' => $t->slug,
                'reply_count' => $t->replyCount,
                'created_at' => $t->createdAt->format('c'),
            ], $recentThreads->items),
            'posts' => array_map(static fn(Post $p) => [
                'id' => $p->id,
                'thread_id' => $p->threadId,
                'body_html' => $p->bodyHtml,
                'vote_score' => $p->voteScore,
                'created_at' => $p->createdAt->format('c'),
            ], $recentPosts->items),
            'badges' => array_map(static fn(UserBadge $b) => [
                'badge' => $b->badge,
                'awarded_at' => $b->awardedAt->format('c'),
            ], $badges),
            'page_title' => 'User Profile',
        ]);
    }
}
