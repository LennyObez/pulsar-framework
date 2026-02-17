<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Realtime;

use Pulsar\Api\Api;

/**
 * Interface for broadcasting real-time events to connected clients.
 */
#[Api(since: '1.0.0')]
interface RealtimeBroadcasterInterface
{
    /**
     * Broadcast an event to all subscribers of a channel.
     */
    public function broadcast(RealtimeEvent $event): void;

    /**
     * Register a user as present in a thread channel.
     */
    public function trackPresence(string $channelId, string $userId): void;

    /**
     * Remove a user's presence from a channel.
     */
    public function removePresence(string $channelId, string $userId): void;

    /**
     * Get all users currently present in a channel.
     *
     * @return list<string> User IDs
     */
    public function getPresence(string $channelId): array;
}
