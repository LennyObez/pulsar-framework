<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Account;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Http\Controller\Page\RendersForumView;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_numeric;
use function max;

/**
 * Account pages: authenticated user's own profile, threads, posts, settings.
 */
#[Internal(reason: 'Forum account controller; implementation detail')]
final readonly class AccountController
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
     * GET /account: My profile overview.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function profile(ServerRequestInterface $request): Response
    {
        $identity = $this->requireAuth($request);

        $profile = $this->profileRepository->findByUser($identity->id());
        $badges = $this->badgeRepository->findByUser($identity->id());

        return $this->respondWithView($request, 'account.profile', [
            'page_title' => 'My Profile',
            'profile' => $profile !== null ? [
                'reputation_score' => $profile->reputationScore,
                'reputation_level' => $profile->reputationLevel()->value,
                'post_count' => $profile->postCount,
                'thread_count' => $profile->threadCount,
                'created_at' => $profile->createdAt->format('c'),
            ] : null,
            'badges' => array_map(static fn(UserBadge $b) => [
                'badge' => $b->badge,
                'awarded_at' => $b->awardedAt->format('c'),
            ], $badges),
        ]);
    }

    /**
     * GET /account/threads: My threads.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function threads(ServerRequestInterface $request): Response
    {
        $identity = $this->requireAuth($request);
        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);

        $result = $this->threadRepository->findByAuthor($identity->id(), $page, 25);

        return $this->respondWithView($request, 'account.threads', [
            'page_title' => 'My Threads',
            'threads' => array_map(static fn(Thread $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'slug' => $t->slug,
                'status' => $t->status->value,
                'reply_count' => $t->replyCount,
                'view_count' => $t->viewCount,
                'created_at' => $t->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'page' => $page,
        ]);
    }

    /**
     * GET /account/posts: My posts.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function posts(ServerRequestInterface $request): Response
    {
        $identity = $this->requireAuth($request);
        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);

        $result = $this->postRepository->findByAuthor($identity->id(), $page, 25);

        return $this->respondWithView($request, 'account.posts', [
            'page_title' => 'My Posts',
            'posts' => array_map(static fn(Post $p) => [
                'id' => $p->id,
                'thread_id' => $p->threadId,
                'body_html' => $p->bodyHtml,
                'vote_score' => $p->voteScore,
                'is_solution' => $p->isSolution,
                'created_at' => $p->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'page' => $page,
        ]);
    }

    /**
     * GET /account/settings: Account settings.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function settings(ServerRequestInterface $request): Response
    {
        $this->requireAuth($request);

        return $this->respondWithView($request, 'account.settings', [
            'page_title' => 'Settings',
        ]);
    }

    /**
     * @throws AuthenticationException
     */
    private function requireAuth(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw AuthenticationException::invalidCredentials();
        }

        return $identity;
    }
}
