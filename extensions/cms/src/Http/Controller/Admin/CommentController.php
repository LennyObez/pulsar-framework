<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for comment moderation.
 *
 * Provides a moderation queue with filtering by status, detail view with
 * parent context and author stats, bulk moderation, and delete actions.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class CommentController
{
    use RendersAdminView;

    public function __construct(
        private CommentRepositoryInterface $commentRepository,
        private CommentServiceInterface $commentService,
        private ?GateInterface $gate = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * List comments for moderation, filtered by status.
     *
     * Default filter is "pending" to show the moderation queue.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        $params = $request->getQueryParams();
        $statusFilter = is_string($params['status'] ?? null) ? $params['status'] : 'pending';
        $page = max(1, is_int($params['page'] ?? null) ? $params['page'] : 1);
        $perPage = min(100, max(1, is_int($params['per_page'] ?? null) ? $params['per_page'] : 20));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->commentRepository->findPendingModeration($tenantId, $page, $perPage);

        $data = [
            'comments' => array_map(static fn(Comment $c) => [
                'id' => $c->id,
                'content_id' => $c->contentId,
                'author_id' => $c->authorId,
                'guest_name' => $c->guestName,
                'body' => $c->body,
                'status' => $c->status->value,
                'created_at' => $c->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'filter' => [
                'status' => $statusFilter,
            ],
        ];

        return $this->respondWithView($request, 'admin.comments.index', $data);
    }

    /**
     * Moderation queue view with pending/approved/spam filter tabs.
     */
    public function queue(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        $params = $request->getQueryParams();
        $filter = is_string($params['filter'] ?? null) ? $params['filter'] : 'pending';
        $page = max(1, is_int($params['page'] ?? null) ? $params['page'] : 1);
        $perPage = min(100, max(1, is_int($params['per_page'] ?? null) ? $params['per_page'] : 20));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->commentRepository->findPendingModeration($tenantId, $page, $perPage);
        $pendingCount = $result->total;

        $data = [
            'comments' => array_map(static fn(Comment $c): array => [
                'id' => $c->id,
                'content_id' => $c->contentId,
                'author_id' => $c->authorId,
                'guest_name' => $c->guestName,
                'body' => $c->body,
                'status' => $c->status->value,
                'created_at' => $c->createdAt->format('c'),
            ], $result->items),
            'pagination' => $result->metaToArray(),
            'filter' => $filter,
            'pendingCount' => $pendingCount,
        ];

        return $this->respondWithView($request, 'admin.comments.queue', $data);
    }

    /**
     * Show a single comment detail for moderation.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        $comment = $this->commentRepository->findById($id);

        if ($comment === null) {
            return Response::json(['error' => 'Comment not found'], 404);
        }

        $data = [
            'id' => $comment->id,
            'content_id' => $comment->contentId,
            'parent_id' => $comment->parentId,
            'author_id' => $comment->authorId,
            'guest_name' => $comment->guestName,
            'guest_email' => $comment->guestEmail,
            'body' => $comment->body,
            'status' => $comment->status->value,
            'edited_at' => $comment->editedAt?->format('c'),
            'created_at' => $comment->createdAt->format('c'),
        ];

        return $this->respondWithView($request, 'admin.comments.show', $data);
    }

    /**
     * Show detailed comment view with parent context and author statistics.
     */
    public function detail(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        $comment = $this->commentRepository->findById($id);

        if ($comment === null) {
            return Response::json(['error' => 'Comment not found'], 404);
        }

        // Load parent comment context if this is a reply
        $parentComment = null;

        if ($comment->parentId !== null) {
            $parent = $this->commentRepository->findById($comment->parentId);

            if ($parent !== null) {
                $parentComment = [
                    'id' => $parent->id,
                    'body' => $parent->body,
                    'guest_name' => $parent->guestName,
                    'author_id' => $parent->authorId,
                    'created_at' => $parent->createdAt->format('c'),
                ];
            }
        }

        $data = [
            'comment' => [
                'id' => $comment->id,
                'content_id' => $comment->contentId,
                'parent_id' => $comment->parentId,
                'author_id' => $comment->authorId,
                'guest_name' => $comment->guestName,
                'guest_email' => $comment->guestEmail,
                'body' => $comment->body,
                'status' => $comment->status->value,
                'ip_hash' => $comment->ipHash,
                'user_agent_hash' => $comment->userAgentHash,
                'edited_at' => $comment->editedAt?->format('c'),
                'edit_window_expires_at' => $comment->editWindowExpiresAt?->format('c'),
                'data_classification' => $comment->dataClassification->value,
                'created_at' => $comment->createdAt->format('c'),
            ],
            'parentComment' => $parentComment,
        ];

        return $this->respondWithView($request, 'admin.comments.detail', $data);
    }

    /**
     * Moderate a comment: approve, reject, or mark as spam.
     */
    public function moderate(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if (!in_array($action, ['approve', 'reject', 'spam'], true)) {
            return Response::json(['error' => 'Invalid action. Must be: approve, reject, or spam'], 400);
        }

        try {
            $comment = match ($action) {
                'approve' => $this->commentService->approve($id, $identity->id(), $reason),
                'reject' => $this->commentService->reject($id, $identity->id(), $reason),
                'spam' => $this->commentService->markSpam($id, $identity->id(), $reason),
            };

            return Response::json([
                'id' => $comment->id,
                'status' => $comment->status->value,
                'action' => $action,
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Delete a comment (soft delete).
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        $comment = $this->commentRepository->findById($id);

        if ($comment === null) {
            return Response::json(['error' => 'Comment not found'], 404);
        }

        $this->commentRepository->delete($comment);

        return Response::json(['id' => $id, 'deleted' => true]);
    }

    /**
     * Process bulk moderation actions on multiple comments.
     *
     * Accepts a list of comment IDs and a bulk action (approve, reject, spam).
     * Each comment is processed individually; failures are collected without
     * blocking the remaining operations.
     */
    public function bulkAction(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $action = is_string($body['bulk_action'] ?? null) ? $body['bulk_action'] : '';
        $rawIds = is_array($body['ids'] ?? null) ? $body['ids'] : [];

        if (!in_array($action, ['approve', 'reject', 'spam'], true)) {
            return Response::json(['error' => 'Invalid bulk action. Must be: approve, reject, or spam'], 400);
        }

        $processed = 0;
        $failed = 0;

        foreach ($rawIds as $id) {
            if (!is_string($id)) {
                continue;
            }

            try {
                match ($action) {
                    'approve' => $this->commentService->approve($id, $identity->id(), 'Bulk action'),
                    'reject' => $this->commentService->reject($id, $identity->id(), 'Bulk action'),
                    'spam' => $this->commentService->markSpam($id, $identity->id(), 'Bulk action'),
                };

                $processed++;
            } catch (CmsException) {
                $failed++;
            }
        }

        return Response::json([
            'action' => $action,
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($rawIds),
        ]);
    }
}
