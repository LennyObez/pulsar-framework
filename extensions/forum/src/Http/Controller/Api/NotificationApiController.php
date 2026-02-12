<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Notification\ForumNotificationRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_numeric;
use function max;

/**
 * Public REST API controller for the user's notification inbox.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class NotificationApiController
{
    public function __construct(
        private ForumNotificationRepositoryInterface $notificationRepository,
    ) {}

    /**
     * GET /api/v1/forum/notifications — Paginated notifications for the authenticated user.
     */
    public function list(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $queryParams = $request->getQueryParams();
        $page = isset($queryParams['page']) && is_numeric($queryParams['page'])
            ? max(1, (int) $queryParams['page'])
            : 1;
        $perPage = isset($queryParams['per_page']) && is_numeric($queryParams['per_page'])
            ? max(1, (int) $queryParams['per_page'])
            : 20;

        $result = $this->notificationRepository->findByUser($identity->id(), $page, $perPage);

        $items = array_map(static fn($n): array => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
            'is_read' => $n->isRead,
            'data' => $n->data,
            'created_at' => $n->createdAt->format('c'),
        ], $result->items);

        return Response::json([
            'data' => $items,
            'meta' => $result->metaToArray(),
        ]);
    }

    /**
     * PATCH /api/v1/forum/notifications/{id}/read — Mark a single notification as read.
     */
    public function markRead(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $notification = $this->notificationRepository->findById($id);

        if ($notification === null || $notification->userId !== $identity->id()) {
            return Response::json(['error' => 'Notification not found', 'status' => 404], 404);
        }

        $this->notificationRepository->markRead($id);

        return Response::json(['data' => ['id' => $id, 'is_read' => true]]);
    }

    /**
     * POST /api/v1/forum/notifications/read-all — Mark all notifications as read.
     */
    public function markAllRead(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $this->notificationRepository->markAllRead($identity->id());

        return Response::json(['data' => ['status' => 'all_read']]);
    }

    /**
     * GET /api/v1/forum/notifications/unread-count — Unread notification count.
     */
    public function unreadCount(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $count = $this->notificationRepository->countUnread($identity->id());

        return Response::json(['data' => ['unread_count' => $count]]);
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
}
