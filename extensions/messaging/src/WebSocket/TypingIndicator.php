<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebSocket;

use Pulsar\Api\Api;
use Pulsar\WebSocket\BroadcastManagerInterface;

/**
 * Broadcasts typing status to conversation participants.
 *
 * Typing indicators are ephemeral; they are broadcast via WebSocket
 * but never persisted. The client is responsible for sending start/stop
 * typing events and the UI auto-clears after a timeout.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TypingIndicator
{
    public function __construct(
        private BroadcastManagerInterface $broadcastManager,
    ) {}

    /**
     * Broadcast that a user has started typing.
     */
    public function startTyping(string $conversationId, string $userId, string $excludeConnectionId): void
    {
        $this->broadcastManager->broadcastExcept(
            'private-conversation.' . $conversationId,
            'typing.start',
            [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ],
            [$excludeConnectionId],
        );
    }

    /**
     * Broadcast that a user has stopped typing.
     */
    public function stopTyping(string $conversationId, string $userId, string $excludeConnectionId): void
    {
        $this->broadcastManager->broadcastExcept(
            'private-conversation.' . $conversationId,
            'typing.stop',
            [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ],
            [$excludeConnectionId],
        );
    }
}
