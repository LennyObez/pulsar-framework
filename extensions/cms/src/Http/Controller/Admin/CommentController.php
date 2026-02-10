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
use function in_array;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for comment moderation.
 *
 * Provides a moderation queue with filtering by status, and actions
 * to approve, reject, or mark comments as spam.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class CommentController
{
    use RendersAdminView;

    public function __construct(
        private CommentRepositoryInterface $commentRepository,
        private CommentServiceInterface $commentService,
        private GateInterface $gate,
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
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

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
     * Moderate a comment: approve, reject, or mark as spam.
     */
    public function moderate(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.comments.moderate');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $action = (string) ($body['action'] ?? '');
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

}
