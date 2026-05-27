<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Post\Post;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function hash;
use function is_array;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for forum posts.
 */
#[Internal(reason: 'Forum REST API controller; implementation detail')]
final readonly class PostApiController
{
    public function __construct(
        private PostRepositoryInterface $postRepository,
        private ForumServiceInterface $forumService,
        private MarkdownRendererInterface $markdown,
        private ForumBodyPolicy $bodyPolicy,
        private ForumConfig $config,
        private ThreadRepositoryInterface $threadRepository,
        private ?GateInterface $gate = null,
    ) {}

    /**
     * GET /api/v1/forum/threads/{threadId}/posts: List posts in a thread.
     */
    public function index(ServerRequestInterface $request, string $threadId): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->postsPerPage));

        $result = $this->postRepository->findByThread($threadId, $page, $perPage);
        $data = array_map(self::serializePost(...), $result->items);

        return Response::json(['data' => $data, 'pagination' => $result->metaToArray()])
            ->withHeader('X-Total-Count', (string) $result->total)
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * POST /api/v1/forum/threads/{threadId}/posts: Create a new post.
     */
    public function create(ServerRequestInterface $request, string $threadId): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        if (!is_string($body['body'] ?? null) || ($body['body'] ?? '') === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['body' => 'Post body is required'],
            ], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $serverParams = $request->getServerParams();
        /** @var mixed $rawRemoteAddr */
        $rawRemoteAddr = $serverParams['REMOTE_ADDR'] ?? null;
        $ipHash = hash('xxh3', is_string($rawRemoteAddr) ? $rawRemoteAddr : 'unknown');
        $userAgentHash = hash('xxh3', $request->getHeaderLine('User-Agent'));
        /** @var mixed $rawParentId */
        $rawParentId = $body['parent_id'] ?? null;
        $parentId = is_string($rawParentId) ? $rawParentId : null;

        /** @var mixed $rawBodyText */
        $rawBodyText = $body['body'] ?? null;
        $rawBody = is_string($rawBodyText) ? $rawBodyText : '';

        try {
            $post = $this->forumService->createPost(
                threadId: $threadId,
                authorId: $identity->id(),
                body: $rawBody,
                bodyHtml: $this->bodyPolicy->sanitize($this->markdown->render($rawBody)),
                ipHash: $ipHash,
                userAgentHash: $userAgentHash,
                tenantId: $tenantId,
                parentId: $parentId,
            );

            return Response::json(['data' => self::serializePost($post)], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/forum/posts/{id}: Show a single post.
     *
     * The $request parameter is accepted (and ignored) so the method signature
     * matches the router's controller dispatch convention used elsewhere in
     * this controller (create/update/destroy all take ServerRequestInterface
     * as the first argument).
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        unset($request);
        $post = $this->postRepository->findById($id);

        if ($post === null || $post->isDeleted()) {
            return Response::json(['error' => 'Post not found', 'status' => 404], 404);
        }

        return Response::json(['data' => self::serializePost($post)]);
    }

    /**
     * PUT /api/v1/forum/posts/{id}: Edit a post.
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        if (!is_string($body['body'] ?? null) || ($body['body'] ?? '') === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['body' => 'Post body is required'],
            ], 422);
        }

        /** @var mixed $rawBodyText */
        $rawBodyText = $body['body'] ?? null;
        $rawBody = is_string($rawBodyText) ? $rawBodyText : '';

        $isModerator = $this->gate !== null && $this->gate->allows($identity, 'forum.moderate');

        try {
            $post = $this->forumService->editPost($id, $rawBody, $this->bodyPolicy->sanitize($this->markdown->render($rawBody)), $identity->id(), $isModerator);

            return Response::json(['data' => self::serializePost($post)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /api/v1/forum/posts/{id}: Soft delete a post.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $post = $this->postRepository->findById($id);

        if ($post === null || $post->isDeleted()) {
            return Response::json(['error' => 'Post not found', 'status' => 404], 404);
        }

        $isOwner = $post->authorId === $identity->id();
        $isModerator = $this->gate !== null && $this->gate->allows($identity, 'forum.moderate');

        if (!$isOwner && !$isModerator) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        try {
            // The controller already validated $isOwner || $isModerator
            // above, so forward the moderator flag to the service for
            // its own author-check (defense in depth, MED-4).
            $this->forumService->deletePost($id, $identity->id(), $isModerator);

            return Response::json(['data' => ['id' => $id, 'status' => 'deleted']]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/posts/{id}/accept: Mark a post as the accepted solution.
     *
     * Only the thread author may accept a solution.
     */
    public function accept(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $post = $this->postRepository->findById($id);

        if ($post === null || $post->isDeleted()) {
            return Response::json(['error' => 'Post not found', 'status' => 404], 404);
        }

        $thread = $this->threadRepository->findById($post->threadId);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found', 'status' => 404], 404);
        }

        if ($thread->authorId !== $identity->id()) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        try {
            $solvedThread = $this->forumService->acceptSolution($thread->id, $id);

            return Response::json([
                'data' => [
                    'thread_id' => $solvedThread->id,
                    'solved_post_id' => $solvedThread->solvedPostId,
                    'status' => 'accepted',
                ],
            ]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw ForumException::unauthorized('authentication_required');
        }

        return $identity;
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
            'can_edit' => $post->canEdit(),
            'created_at' => $post->createdAt->format('c'),
            'updated_at' => $post->updatedAt->format('c'),
        ];
    }
}
