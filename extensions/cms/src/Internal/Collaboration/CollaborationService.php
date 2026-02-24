<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Collaboration;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationSession;
use Pulsar\Extension\Cms\Collaboration\CrdtDocument;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function random_bytes;

/**
 * Manages CRDT collaboration sessions and document state.
 *
 * The actual CRDT merge is client-side (Yjs). The server stores the latest
 * full state snapshot and coordinates awareness (cursors/selections) between
 * connected clients via REST polling.
 *
 * @psalm-api Resolved from the DI container by admin controllers; not
 *            instantiated by name.
 */
#[Internal(reason: 'Collaboration service; use CollaborationRepositoryInterface for persistence API')]
final readonly class CollaborationService
{
    public function __construct(
        private CollaborationRepositoryInterface $repository,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function joinSession(string $contentId, string $userId, string $userName): CollaborationSession
    {
        $now = new DateTimeImmutable();
        $sessionId = bin2hex(random_bytes(16));

        $session = new CollaborationSession(
            id: $sessionId,
            contentId: $contentId,
            userId: $userId,
            userName: $userName,
            cursorPosition: null,
            selectionRange: null,
            connectedAt: $now,
            lastSeenAt: $now,
        );

        $this->repository->saveSession($session);

        // Ensure a CRDT document exists for this content
        $document = $this->repository->getDocument($contentId);

        if ($document === null) {
            $this->repository->saveDocument(CrdtDocument::initial($contentId));
        }

        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $userId,
            'cms.collaboration.joined',
            $contentId,
            ['session_id' => $sessionId, 'user_name' => $userName],
        );

        return $session;
    }

    public function leaveSession(string $sessionId): void
    {
        $this->repository->removeSession($sessionId);
    }

    public function updateAwareness(
        string $contentId,
        string $sessionId,
        ?string $cursorPosition,
        ?string $selectionRange,
    ): void {
        $sessions = $this->repository->getActiveSessions($contentId);

        foreach ($sessions as $session) {
            if ($session->id === $sessionId) {
                $updated = new CollaborationSession(
                    id: $session->id,
                    contentId: $session->contentId,
                    userId: $session->userId,
                    userName: $session->userName,
                    cursorPosition: $cursorPosition,
                    selectionRange: $selectionRange,
                    connectedAt: $session->connectedAt,
                    lastSeenAt: new DateTimeImmutable(),
                );

                $this->repository->saveSession($updated);

                return;
            }
        }
    }

    public function applyUpdate(string $contentId, string $update, string $userId): CrdtDocument
    {
        $document = $this->repository->getDocument($contentId) ?? CrdtDocument::initial($contentId);
        $updated = $document->applyUpdate($update);

        $this->repository->saveDocument($updated);

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $userId,
            'cms.collaboration.update_applied',
            $contentId,
            ['version' => $updated->version],
        );

        return $updated;
    }

    public function getDocument(string $contentId): ?CrdtDocument
    {
        return $this->repository->getDocument($contentId);
    }

    /**
     * @return list<CollaborationSession>
     */
    public function getActiveSessions(string $contentId): array
    {
        return $this->repository->getActiveSessions($contentId);
    }
}
