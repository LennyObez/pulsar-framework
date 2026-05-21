<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for post management: edit, delete, hide.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class PostController
{
    use RendersAdminView;

    public function __construct(
        private PostRepositoryInterface $postRepository,
        private ForumServiceInterface $forumService,
        private MarkdownRendererInterface $markdown,
        private ForumConfig $config,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/threads/{threadId}/posts: List posts in a thread.
     */
    public function index(ServerRequestInterface $request, string $threadId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.posts');

        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->postsPerPage));

        $result = $this->postRepository->findByThread($threadId, $page, $perPage);

        $data = [
            'data' => array_map(self::serializePost(...), $result->items),
            'pagination' => $result->metaToArray(),
            'thread_id' => $threadId,
        ];

        return $this->respondWithView($request, 'admin.forum.posts.index', $data);
    }

    /**
     * GET /admin/forum/posts/{id}: Show a single post.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.posts');

        $post = $this->postRepository->findById($id);

        if ($post === null) {
            return Response::json(['error' => 'Post not found'], 404);
        }

        return $this->respondWithView($request, 'admin.forum.posts.show', [
            'post' => self::serializePost($post),
        ]);
    }

    /**
     * PUT /admin/forum/posts/{id}: Admin edit a post (bypasses edit window).
     */
    public function edit(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.posts.edit');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawBody */
        $rawBody = $body['body'] ?? null;
        $newBody = is_string($rawBody) ? $rawBody : '';

        if ($newBody === '') {
            return Response::json(['error' => 'Post body is required'], 422);
        }

        try {
            $post = $this->forumService->editPost($id, $newBody, $this->markdown->render($newBody), $identity->id(), isModerator: true);

            return Response::json(['data' => self::serializePost($post)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /admin/forum/posts/{id}: Soft delete a post.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.posts.delete');

        try {
            // Admin endpoint: passing isModerator=true bypasses the
            // author check inside ForumService::deletePost (MED-4).
            // The admin permission was already validated by authorize()
            // above, so this caller is allowed to delete any post.
            $this->forumService->deletePost($id, $identity->id(), isModerator: true);

            return Response::json(['data' => ['id' => $id, 'status' => 'deleted']]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializePost(Post $post): array
    {
        return [
            'id' => $post->id,
            'thread_id' => $post->threadId,
            'parent_id' => $post->parentId,
            'author_id' => $post->authorId,
            'body' => $post->body,
            'body_html' => $post->bodyHtml,
            'is_solution' => $post->isSolution,
            'vote_score' => $post->voteScore,
            'edit_count' => $post->editCount,
            'edited_by' => $post->editedBy,
            'edited_at' => $post->editedAt?->format('c'),
            'is_deleted' => $post->isDeleted(),
            'created_at' => $post->createdAt->format('c'),
            'updated_at' => $post->updatedAt->format('c'),
        ];
    }
}
