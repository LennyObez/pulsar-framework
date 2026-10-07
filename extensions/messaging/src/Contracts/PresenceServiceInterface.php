<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Contracts;

use Pulsar\Api\Api;

/**
 * Tracks user online/offline presence status.
 * @api
 */
#[Api(since: '1.0.0')]
interface PresenceServiceInterface
{
    /**
     * Mark a user as online.
     */
    public function setOnline(string $userId): void;

    /**
     * Mark a user as offline.
     */
    public function setOffline(string $userId): void;

    /**
     * Check whether a user is currently online.
     */
    public function isOnline(string $userId): bool;

    /**
     * Get all currently online user IDs.
     *
     * @return list<string>
     */
    public function getOnlineUsers(): array;
}
