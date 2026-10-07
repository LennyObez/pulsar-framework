<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Internal\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessageRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Domain\Conversation;
use Pulsar\Extension\Messaging\Domain\ConversationType;
use Pulsar\Extension\Messaging\Domain\Message;
use Pulsar\Extension\Messaging\Domain\MessageType;
use Pulsar\Extension\Messaging\Domain\Participant;

use function bin2hex;
use function count;
use function random_bytes;

/**
 * Core messaging service implementation.
 *
 * Handles conversation lifecycle and message persistence. Messages
 * arrive already encrypted (E2EE) and are stored as-is: this service
 * never touches plaintext content.
 */
#[Internal(reason: 'Use MessagingServiceInterface for public API')]
final readonly class MessagingService implements MessagingServiceInterface
{
    public function __construct(
        private ConversationRepositoryInterface $conversationRepository,
        private MessageRepositoryInterface $messageRepository,
    ) {}

    public function createConversation(
        ConversationType $type,
        array $participantIds,
        ?string $title = null,
    ): Conversation {
        if ($type === ConversationType::Direct && count($participantIds) !== 2) {
            throw new InvalidArgumentException('Direct conversations must have exactly 2 participants');
        }

        if (count($participantIds) < 2) {
            throw new InvalidArgumentException('Conversations must have at least 2 participants');
        }

        // For direct conversations, check if one already exists
        if ($type === ConversationType::Direct) {
            $existing = $this->conversationRepository->findDirect($participantIds[0], $participantIds[1]);

            if ($existing !== null) {
                return $existing;
            }
        }

        $now = new DateTimeImmutable();

        $conversation = new Conversation(
            id: $this->generateId(),
            type: $type,
            participantIds: $participantIds,
            title: $title,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->conversationRepository->save($conversation);

        return $conversation;
    }

    public function sendMessage(
        string $conversationId,
        string $senderId,
        string $encryptedContent,
        string $nonce,
        MessageType $type = MessageType::Text,
    ): Message {
        $conversation = $this->conversationRepository->findById($conversationId);

        if ($conversation === null) {
            throw new InvalidArgumentException('Conversation not found: ' . $conversationId);
        }

        if (!$conversation->hasParticipant($senderId)) {
            throw new InvalidArgumentException('User is not a participant in this conversation');
        }

        $message = new Message(
            id: $this->generateId(),
            conversationId: $conversationId,
            senderId: $senderId,
            encryptedContent: $encryptedContent,
            nonce: $nonce,
            type: $type,
            timestamp: new DateTimeImmutable(),
        );

        $this->messageRepository->save($message);

        return $message;
    }

    public function getMessages(
        string $conversationId,
        int $page = 1,
        int $perPage = 50,
    ): PaginationResult {
        return $this->messageRepository->findByConversation($conversationId, $page, $perPage);
    }

    public function markRead(string $conversationId, string $userId): void
    {
        $conversation = $this->conversationRepository->findById($conversationId);

        if ($conversation === null || !$conversation->hasParticipant($userId)) {
            return;
        }

        // Persist the read state: the conversation repository handles participant updates
        $now = new DateTimeImmutable();
        $updatedConversation = new Conversation(
            id: $conversation->id,
            type: $conversation->type,
            participantIds: $conversation->participantIds,
            title: $conversation->title,
            createdAt: $conversation->createdAt,
            updatedAt: $now,
        );

        $this->conversationRepository->save($updatedConversation);
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
