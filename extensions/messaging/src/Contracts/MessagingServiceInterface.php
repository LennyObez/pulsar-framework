<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Contracts;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Messaging\Domain\Conversation;
use Pulsar\Extension\Messaging\Domain\ConversationType;
use Pulsar\Extension\Messaging\Domain\Message;
use Pulsar\Extension\Messaging\Domain\MessageType;

/**
 * Core messaging service for creating conversations and sending encrypted messages.
 * @api
 */
#[Api(since: '1.0.0')]
interface MessagingServiceInterface
{
    /**
     * Create a new conversation.
     *
     * @param list<string> $participantIds User IDs to include
     */
    public function createConversation(
        ConversationType $type,
        array $participantIds,
        ?string $title = null,
    ): Conversation;

    /**
     * Send an encrypted message to a conversation.
     *
     * @param string $conversationId Target conversation
     * @param string $senderId Sender's user ID
     * @param string $encryptedContent E2EE ciphertext (base64)
     * @param string $nonce Encryption nonce (base64)
     * @param MessageType $type Message content type
     */
    public function sendMessage(
        string $conversationId,
        string $senderId,
        string $encryptedContent,
        string $nonce,
        MessageType $type = MessageType::Text,
    ): Message;

    /**
     * Get paginated messages for a conversation.
     *
     * @return PaginationResult<Message>
     */
    public function getMessages(
        string $conversationId,
        int $page = 1,
        int $perPage = 50,
    ): PaginationResult;

    /**
     * Mark all messages in a conversation as read up to now.
     */
    public function markRead(string $conversationId, string $userId): void;
}
