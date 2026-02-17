<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Messaging;

use InvalidArgumentException;
use Pulsar\Api\Api;

/**
 * Service for private messaging between forum users.
 */
#[Api(since: '1.0.0')]
interface MessagingServiceInterface
{
    /**
     * Start a new conversation between users.
     *
     * @param list<string> $participantIds
     */
    public function createConversation(string $initiatorId, array $participantIds, string $subject = ''): Conversation;

    /**
     * Send a message in an existing conversation.
     *
     * @throws InvalidArgumentException If user is not a participant
     */
    public function sendMessage(string $conversationId, string $senderId, string $body): PrivateMessage;

    /**
     * Get a user's conversations, newest first.
     *
     * @return list<Conversation>
     */
    public function getConversations(string $userId, int $limit = 20, int $offset = 0): array;

    /**
     * Get messages in a conversation, ordered chronologically.
     *
     * @return list<PrivateMessage>
     */
    public function getMessages(string $conversationId, string $userId, int $limit = 50, int $offset = 0): array;

    /**
     * Mark all messages in a conversation as read by a user.
     */
    public function markConversationRead(string $conversationId, string $userId): void;

    /**
     * Get unread message count for a user.
     */
    public function getUnreadCount(string $userId): int;

    /**
     * Delete (soft-delete) a message.
     */
    public function deleteMessage(string $messageId, string $userId): void;
}
