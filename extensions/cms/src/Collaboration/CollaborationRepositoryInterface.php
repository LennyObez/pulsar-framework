<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Collaboration;

use Pulsar\Api\Api;

/**
 * Persistence contract for CRDT collaboration state and sessions.
 *
 * @psalm-api Public binding contract; implemented by DbCollaborationRepository
 *            and consumed by CollaborationService.
 */
#[Api(since: '1.0.0')]
interface CollaborationRepositoryInterface
{
    public function getDocument(string $contentId): ?CrdtDocument;

    public function saveDocument(CrdtDocument $document): void;

    /**
     * @return list<CollaborationSession>
     */
    public function getActiveSessions(string $contentId): array;

    public function saveSession(CollaborationSession $session): void;

    public function removeSession(string $sessionId): void;

    /**
     * Remove sessions whose last_seen_at exceeds the max age.
     *
     * @return int Number of sessions removed
     */
    public function cleanupExpiredSessions(int $maxAgeMinutes = 30): int;
}
