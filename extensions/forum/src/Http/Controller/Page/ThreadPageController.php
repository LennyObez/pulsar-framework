<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Page;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function max;
use function min;

/**
 * Thread page: shows original post and paginated replies.
 */
#[Internal(reason: 'Forum page controller; implementation detail')]
final readonly class ThreadPageController
{
    use RendersForumView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private PostRepositoryInterface $postRepository,
        private ForumConfig $config,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    private function getTemplateEngine(): ?TemplateEngineInterface
    {
        return $this->templateEngine;
    }

    /**
     * GET /t/{slug}: Show a thread with its posts.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request, string $slug): Response
    {
        $thread = $this->threadRepository->findBySlug($slug);

        if ($thread === null) {
            return $this->respondWithView($request, 'forum.404', [
                'page_title' => 'Thread Not Found',
                'message' => 'The thread you are looking for does not exist.',
            ], 404);
        }

        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->postsPerPage));

        $posts = $this->postRepository->findByThread($thread->id, $page, $perPage);

        $postData = array_map(static fn(Post $p) => [
            'id' => $p->id,
            'author_id' => $p->authorId,
            'parent_id' => $p->parentId,
            'body_html' => $p->bodyHtml,
            'body' => $p->body,
            'is_solution' => $p->isSolution,
            'vote_score' => $p->voteScore,
            'edit_count' => $p->editCount,
            'created_at' => $p->createdAt->format('c'),
            'edited_at' => $p->editedAt?->format('c'),
        ], $posts->items);

        // Paginated thread pages get noindex to avoid duplicate content
        $metaRobots = $page > 1 ? 'noindex, follow' : 'index, follow';

        return $this->respondWithView($request, 'forum.thread', [
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'author_id' => $thread->authorId,
                'category_id' => $thread->categoryId,
                'type' => $thread->type->value,
                'status' => $thread->status->value,
                'is_pinned' => $thread->isPinned,
                'is_locked' => $thread->isLocked,
                'is_solved' => $thread->solvedPostId !== null,
                'solved_post_id' => $thread->solvedPostId,
                'reply_count' => $thread->replyCount,
                'view_count' => $thread->viewCount,
                'vote_score' => $thread->voteScore,
                'created_at' => $thread->createdAt->format('c'),
                'last_activity_at' => $thread->lastActivityAt?->format('c'),
            ],
            'posts' => $postData,
            'pagination' => $posts->metaToArray(),
            'page' => $page,
            'page_title' => $thread->title,
            'meta_description' => $thread->title . ': ' . $thread->replyCount . ' replies',
            'meta_robots' => $metaRobots,
            'canonical_url' => '/t/' . $thread->slug,
        ]);
    }
}
