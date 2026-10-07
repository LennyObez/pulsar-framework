<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebSocket;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Domain\MessageType;
use Pulsar\WebSocket\BroadcastManagerInterface;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Handles WebSocket connections for real-time message delivery.
 *
 * Messages arrive encrypted from the client. The handler persists them
 * and broadcasts the ciphertext to conversation participants: the server
 * never decrypts message content.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MessagingWebSocketHandler
{
    public function __construct(
        private MessagingServiceInterface $messagingService,
        private ConversationRepositoryInterface $conversationRepository,
        private BroadcastManagerInterface $broadcastManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Handle an incoming WebSocket message.
     *
     * @param string $connectionId The WebSocket connection ID
     * @param string $userId The authenticated user ID
     * @param string $rawPayload The raw JSON message from the client
     */
    public function handleMessage(string $connectionId, string $userId, string $rawPayload): void
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->sendError($connectionId, 'Invalid JSON payload');
            return;
        }

        $action = $data['action'] ?? null;

        if (!is_string($action)) {
            $this->sendError($connectionId, 'Missing action field');
            return;
        }

        match ($action) {
            'send_message' => $this->handleSendMessage($connectionId, $userId, $data),
            'mark_read' => $this->handleMarkRead($connectionId, $userId, $data),
            'subscribe' => $this->handleSubscribe($connectionId, $userId, $data),
            default => $this->sendError($connectionId, 'Unknown action: ' . $action),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleSendMessage(string $connectionId, string $userId, array $data): void
    {
        /** @var mixed $conversationId */
        $conversationId = $data['conversation_id'] ?? null;
        /** @var mixed $encryptedContent */
        $encryptedContent = $data['encrypted_content'] ?? null;
        /** @var mixed $nonce */
        $nonce = $data['nonce'] ?? null;
        /** @var mixed $typeValue */
        $typeValue = $data['type'] ?? 'text';

        if (!is_string($conversationId) || !is_string($encryptedContent) || !is_string($nonce)) {
            $this->sendError($connectionId, 'Missing required fields: conversation_id, encrypted_content, nonce');
            return;
        }

        $conversation = $this->conversationRepository->findById($conversationId);

        if ($conversation === null || !$conversation->hasParticipant($userId)) {
            $this->sendError($connectionId, 'Conversation not found or access denied');
            return;
        }

        $type = MessageType::tryFrom((string) $typeValue) ?? MessageType::Text;

        $message = $this->messagingService->sendMessage(
            $conversationId,
            $userId,
            $encryptedContent,
            $nonce,
            $type,
        );

        // Broadcast to all participants in the conversation channel
        $channel = 'private-conversation.' . $conversationId;
        $this->broadcastManager->broadcastExcept(
            $channel,
            'message.new',
            [
                'id' => $message->id,
                'conversation_id' => $message->conversationId,
                'sender_id' => $message->senderId,
                'encrypted_content' => $message->encryptedContent,
                'nonce' => $message->nonce,
                'type' => $message->type->value,
                'timestamp' => $message->timestamp->format('c'),
            ],
            [$connectionId],
        );

        // Confirm delivery to sender
        $this->broadcastManager->sendTo($connectionId, 'message.sent', [
            'id' => $message->id,
            'conversation_id' => $conversationId,
            'timestamp' => $message->timestamp->format('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleMarkRead(string $connectionId, string $userId, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? null;

        if (!is_string($conversationId)) {
            $this->sendError($connectionId, 'Missing conversation_id');
            return;
        }

        $this->messagingService->markRead($conversationId, $userId);

        // Notify other participants about read receipt
        $channel = 'private-conversation.' . $conversationId;
        $this->broadcastManager->broadcastExcept(
            $channel,
            'message.read',
            [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'read_at' => new DateTimeImmutable()->format('c'),
            ],
            [$connectionId],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleSubscribe(string $connectionId, string $userId, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? null;

        if (!is_string($conversationId)) {
            $this->sendError($connectionId, 'Missing conversation_id');
            return;
        }

        $conversation = $this->conversationRepository->findById($conversationId);

        if ($conversation === null || !$conversation->hasParticipant($userId)) {
            $this->sendError($connectionId, 'Cannot subscribe: conversation not found or access denied');
            return;
        }

        $this->broadcastManager->sendTo($connectionId, 'subscribed', [
            'conversation_id' => $conversationId,
            'channel' => 'private-conversation.' . $conversationId,
        ]);
    }

    private function sendError(string $connectionId, string $message): void
    {
        $this->broadcastManager->sendTo($connectionId, 'error', [
            'message' => $message,
        ]);

        $this->logger->warning('Messaging WebSocket error: {message}', [
            'message' => $message,
            'connection_id' => $connectionId,
        ]);
    }
}
