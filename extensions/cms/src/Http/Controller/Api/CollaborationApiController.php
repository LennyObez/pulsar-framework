<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Internal\Collaboration\CollaborationService;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * REST API controller for real-time collaborative editing.
 *
 * Provides endpoints for CRDT state synchronization and session awareness
 * via polling. Clients (Yjs) poll these endpoints every ~2 seconds.
 */
#[Internal(reason: 'CMS API controller; implementation detail')]
final readonly class CollaborationApiController
{
    public function __construct(
        private CollaborationService $collaborationService,
    ) {}

    /**
     * GET /api/v1/collaboration/{contentId}/state
     *
     * Returns the current CRDT document state and active collaboration sessions.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function getState(ServerRequestInterface $request): Response
    {
        $contentId = $this->extractContentId($request);
        $document = $this->collaborationService->getDocument($contentId);
        $sessions = $this->collaborationService->getActiveSessions($contentId);

        return Response::json([
            'document' => $document !== null ? [
                'content_id' => $document->contentId,
                'state_vector' => $document->stateVector,
                'version' => $document->version,
                'updated_at' => $document->updatedAt->format('c'),
            ] : null,
            'sessions' => array_map(static fn($s) => [
                'id' => $s->id,
                'user_id' => $s->userId,
                'user_name' => $s->userName,
                'cursor_position' => $s->cursorPosition,
                'selection_range' => $s->selectionRange,
                'connected_at' => $s->connectedAt->format('c'),
                'last_seen_at' => $s->lastSeenAt->format('c'),
            ], $sessions),
        ]);
    }

    /**
     * POST /api/v1/collaboration/{contentId}/update
     *
     * Applies a CRDT state update from the client.
     * Body: {"update": "<base64>", "user_id": "<id>"}
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function applyUpdate(ServerRequestInterface $request): Response
    {
        $contentId = $this->extractContentId($request);
        $body = $this->parseJsonBody($request);

        $update = $this->requireString($body, 'update');
        $userId = $this->requireString($body, 'user_id');

        $document = $this->collaborationService->applyUpdate($contentId, $update, $userId);

        return Response::json([
            'content_id' => $document->contentId,
            'version' => $document->version,
            'updated_at' => $document->updatedAt->format('c'),
        ]);
    }

    /**
     * POST /api/v1/collaboration/{contentId}/join
     *
     * Joins a collaboration session.
     * Body: {"user_id": "<id>", "user_name": "<name>"}
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function join(ServerRequestInterface $request): Response
    {
        $contentId = $this->extractContentId($request);
        $body = $this->parseJsonBody($request);

        $userId = $this->requireString($body, 'user_id');
        $userName = $this->requireString($body, 'user_name');

        $session = $this->collaborationService->joinSession($contentId, $userId, $userName);

        return Response::json([
            'session_id' => $session->id,
            'content_id' => $session->contentId,
            'user_id' => $session->userId,
            'user_name' => $session->userName,
            'connected_at' => $session->connectedAt->format('c'),
        ], 201);
    }

    /**
     * POST /api/v1/collaboration/{contentId}/leave
     *
     * Leaves a collaboration session.
     * Body: {"session_id": "<id>"}
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function leave(ServerRequestInterface $request): Response
    {
        $body = $this->parseJsonBody($request);
        $sessionId = $this->requireString($body, 'session_id');

        $this->collaborationService->leaveSession($sessionId);

        return Response::json(['status' => 'ok']);
    }

    /**
     * POST /api/v1/collaboration/{contentId}/awareness
     *
     * Updates cursor/selection position for awareness.
     * Body: {"session_id": "<id>", "cursor_position": "...", "selection_range": "..."}
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function awareness(ServerRequestInterface $request): Response
    {
        $contentId = $this->extractContentId($request);
        $body = $this->parseJsonBody($request);

        $sessionId = $this->requireString($body, 'session_id');
        $cursorPosition = isset($body['cursor_position']) && is_string($body['cursor_position'])
            ? $body['cursor_position']
            : null;
        $selectionRange = isset($body['selection_range']) && is_string($body['selection_range'])
            ? $body['selection_range']
            : null;

        $this->collaborationService->updateAwareness($contentId, $sessionId, $cursorPosition, $selectionRange);

        return Response::json(['status' => 'ok']);
    }

    private function extractContentId(ServerRequestInterface $request): string
    {
        /** @var string $contentId */
        $contentId = $request->getAttribute('contentId', '');

        return $contentId;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $rawBody = (string) $request->getBody();

        if ($rawBody === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requireString(array $body, string $key): string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            return '';
        }

        return $body[$key];
    }
}
