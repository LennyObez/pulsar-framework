<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Handler;

use Pulsar\Api\Api;

/**
 * Extended session handler contract for Pulsar's session management.
 *
 * Extends PHP's native SessionHandlerInterface with capability queries
 * and session management operations (listing, revocation, concurrency).
 */
#[Api(since: '1.0.0')]
interface SessionHandlerInterface extends \SessionHandlerInterface
{
    /**
     * Whether this handler supports concurrent session limits.
     *
     * Only Redis and Database handlers support this. File, Cookie, and Array
     * handlers return false and throw on concurrency operations.
     */
    public function supportsConcurrencyControl(): bool;

    /**
     * Whether this handler supports listing active sessions for a user.
     */
    public function supportsSessionListing(): bool;

    /**
     * Whether this handler supports revoking individual sessions.
     */
    public function supportsRevocation(): bool;

    /**
     * List all active sessions for a user.
     *
     * @return list<array{id: string, last_activity: int, ip_address: string, user_agent: string, created_at: int}>
     *
     * @throws \Pulsar\Security\Exception\SecurityException If the handler does not support session listing
     */
    public function listSessions(string $userId): array;

    /**
     * Revoke a specific session by ID.
     *
     * @throws \Pulsar\Security\Exception\SecurityException If the handler does not support revocation
     */
    public function revokeSession(string $sessionId): bool;

    /**
     * Get the count of active sessions for a user.
     *
     * @throws \Pulsar\Security\Exception\SecurityException If the handler does not support concurrency control
     */
    public function getActiveSessions(string $userId): int;
}
