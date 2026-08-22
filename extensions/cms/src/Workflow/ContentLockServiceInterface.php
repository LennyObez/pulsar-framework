<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use Pulsar\Api\Api;

/**
 * Service interface for pessimistic lease-based content locking.
 *
 * Prevents simultaneous edits by maintaining exclusive locks
 * with automatic expiration and heartbeat renewal.
 *
 * @psalm-api Public binding contract; implemented by ContentLockService and
 *            consumed by editor middleware and admin controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContentLockServiceInterface
{
    /**
     * Acquire a lock on a content item for the given user.
     *
     * @return ContentLock|null The acquired lock, or null if contention prevents acquisition
     */
    public function acquire(string $contentId, string $userId, ?string $locale = null): ?ContentLock;

    /**
     * Release a lock held by the given user.
     */
    public function release(string $contentId, string $userId): void;

    /**
     * Refresh the lock expiration (called periodically while editor is open).
     */
    public function heartbeat(string $contentId, string $userId): ?ContentLock;

    /**
     * Force-unlock a content item regardless of who holds the lock.
     * Requires cms.content.force_unlock permission.
     *
     * @param string|null $actorId User performing the force unlock (for audit trail)
     */
    public function forceUnlock(string $contentId, ?string $actorId = null): void;

    /**
     * Check whether a content item is currently locked.
     */
    public function isLocked(string $contentId, ?string $locale = null): ?ContentLock;

    /**
     * Get current lock information for a content item (all locales).
     *
     * Convenience wrapper around isLocked() without locale filtering.
     */
    public function getLockInfo(string $contentId): ?ContentLock;

    /**
     * Remove all expired locks from the database.
     */
    public function cleanupExpired(): int;
}
