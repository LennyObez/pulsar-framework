<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for thread management — lock, pin, move, delete.
 */
#[Internal(reason: 'Forum admin controller — implementation detail')]
final readonly class ThreadController
{
    use RendersAdminView;

    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private ForumServiceInterface $forumService,
        private ForumConfig $config,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/threads — List threads with pagination.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads');

        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->threadRepository->findRecent($page, $perPage, $tenantId);

        $data = [
            'data' => array_map(self::serializeThread(...), $result->items),
            'pagination' => $result->metaToArray(),
        ];

        return $this->respondWithView($request, 'admin.forum.threads.index', $data);
    }

    /**
     * GET /admin/forum/threads/{id} — Show a single thread.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads');

        $thread = $this->threadRepository->findById($id);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found'], 404);
        }

        return $this->respondWithView($request, 'admin.forum.threads.show', [
            'thread' => self::serializeThread($thread),
        ]);
    }

    /**
     * POST /admin/forum/threads/{id}/lock — Lock a thread.
     */
    public function lock(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads.moderate');

        try {
            $thread = $this->forumService->lockThread($id);

            return Response::json(['data' => self::serializeThread($thread)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/threads/{id}/unlock — Unlock a thread.
     */
    public function unlock(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads.moderate');

        try {
            $thread = $this->forumService->unlockThread($id);

            return Response::json(['data' => self::serializeThread($thread)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/threads/{id}/pin — Pin a thread.
     */
    public function pin(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads.moderate');

        try {
            $thread = $this->forumService->pinThread($id);

            return Response::json(['data' => self::serializeThread($thread)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/threads/{id}/unpin — Unpin a thread.
     */
    public function unpin(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads.moderate');

        try {
            $thread = $this->forumService->unpinThread($id);

            return Response::json(['data' => self::serializeThread($thread)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/forum/threads/{id}/move — Move a thread to a different category.
     */
    public function move(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads.moderate');

        $thread = $this->threadRepository->findById($id);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $categoryId = is_string($body['category_id'] ?? null) ? $body['category_id'] : '';

        if ($categoryId === '') {
            return Response::json(['error' => 'Category ID is required'], 422);
        }

        try {
            $moved = $thread->moveToCategory($categoryId);
            $this->threadRepository->save($moved);

            return Response::json(['data' => self::serializeThread($moved)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /admin/forum/threads/{id} — Soft delete a thread.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.threads.delete');

        try {
            $this->forumService->deleteThread($id);

            return Response::json(['data' => ['id' => $id, 'status' => 'deleted']]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeThread(Thread $thread): array
    {
        return [
            'id' => $thread->id,
            'tenant_id' => $thread->tenantId,
            'category_id' => $thread->categoryId,
            'author_id' => $thread->authorId,
            'title' => $thread->title,
            'slug' => $thread->slug,
            'type' => $thread->type->value,
            'status' => $thread->status->value,
            'is_pinned' => $thread->isPinned,
            'is_locked' => $thread->isLocked,
            'solved_post_id' => $thread->solvedPostId,
            'reply_count' => $thread->replyCount,
            'view_count' => $thread->viewCount,
            'vote_score' => $thread->voteScore,
            'last_activity_at' => $thread->lastActivityAt?->format('c'),
            'created_at' => $thread->createdAt->format('c'),
            'updated_at' => $thread->updatedAt->format('c'),
        ];
    }
}
