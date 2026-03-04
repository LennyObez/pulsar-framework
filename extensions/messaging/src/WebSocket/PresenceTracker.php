<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebSocket;

use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Contracts\PresenceServiceInterface;
use Pulsar\WebSocket\BroadcastManagerInterface;

/**
 * Tracks online/offline status via WebSocket connection state.
 *
 * When a user connects via WebSocket, they are marked online.
 * When disconnected, they are marked offline. Status changes are
 * broadcast to relevant presence channels.
 */
#[Api(since: '1.0.0')]
final readonly class PresenceTracker
{
    public function __construct(
        private PresenceServiceInterface $presenceService,
        private BroadcastManagerInterface $broadcastManager,
    ) {}

    /**
     * Handle a user coming online (WebSocket connected).
     */
    public function onConnect(string $userId): void
    {
        $this->presenceService->setOnline($userId);

        $this->broadcastManager->broadcast(
            'presence-global',
            'user.online',
            ['user_id' => $userId],
        );
    }

    /**
     * Handle a user going offline (WebSocket disconnected).
     */
    public function onDisconnect(string $userId): void
    {
        $this->presenceService->setOffline($userId);

        $this->broadcastManager->broadcast(
            'presence-global',
            'user.offline',
            ['user_id' => $userId],
        );
    }

    /**
     * Check whether a user is currently online.
     */
    public function isOnline(string $userId): bool
    {
        return $this->presenceService->isOnline($userId);
    }

    /**
     * Get all currently online users.
     *
     * @return list<string>
     */
    public function getOnlineUsers(): array
    {
        return $this->presenceService->getOnlineUsers();
    }
}
