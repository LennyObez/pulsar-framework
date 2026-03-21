<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Http\Controller;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Domain\MessageType;
use Pulsar\Http\JsonResponse;
use Pulsar\Http\RequestInterface;
use Pulsar\Http\ResponseInterface;

use function is_array;
use function is_string;

/**
 * REST API controller for sending and retrieving encrypted messages.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MessageController
{
    public function __construct(
        private MessagingServiceInterface $messagingService,
        private ConversationRepositoryInterface $conversationRepository,
    ) {}

    /**
     * List messages in a conversation (paginated).
     */
    public function index(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');
        $conversationId = $request->attribute('conversationId');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
        }

        if (!is_string($conversationId)) {
            return JsonResponse::create(['error' => 'Missing conversation ID'], 400);
        }

        $conversation = $this->conversationRepository->findById($conversationId);

        if ($conversation === null || !$conversation->hasParticipant($userId)) {
            return JsonResponse::create(['error' => 'Conversation not found'], 404);
        }

        $page = (int) ($request->queryParam('page') ?? 1);
        $perPage = (int) ($request->queryParam('per_page') ?? 50);

        $result = $this->messagingService->getMessages($conversationId, $page, $perPage);

        $messages = [];
        foreach ($result->items as $message) {
            $messages[] = [
                'id' => $message->id,
                'sender_id' => $message->senderId,
                'encrypted_content' => $message->encryptedContent,
                'nonce' => $message->nonce,
                'type' => $message->type->value,
                'timestamp' => $message->timestamp->format('c'),
            ];
        }

        return JsonResponse::create([
            'messages' => $messages,
            'total' => $result->total,
            'page' => $result->currentPage,
            'per_page' => $result->perPage,
            'has_more' => $result->hasMore,
        ]);
    }

    /**
     * Send an encrypted message.
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');
        $conversationId = $request->attribute('conversationId');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
        }

        if (!is_string($conversationId)) {
            return JsonResponse::create(['error' => 'Missing conversation ID'], 400);
        }

        $body = $request->parsedBody();

        if (!is_array($body)) {
            return JsonResponse::create(['error' => 'Invalid request body'], 400);
        }

        $encryptedContent = $body['encrypted_content'] ?? null;
        $nonce = $body['nonce'] ?? null;

        if (!is_string($encryptedContent) || !is_string($nonce)) {
            return JsonResponse::create([
                'error' => 'Missing required fields: encrypted_content, nonce',
            ], 400);
        }

        $type = MessageType::tryFrom((string) ($body['type'] ?? 'text')) ?? MessageType::Text;

        try {
            $message = $this->messagingService->sendMessage(
                $conversationId,
                $userId,
                $encryptedContent,
                $nonce,
                $type,
            );
        } catch (InvalidArgumentException $e) {
            return JsonResponse::create(['error' => $e->getMessage()], 422);
        }

        return JsonResponse::create([
            'id' => $message->id,
            'conversation_id' => $message->conversationId,
            'timestamp' => $message->timestamp->format('c'),
        ], 201);
    }

    /**
     * Mark conversation messages as read.
     */
    public function markRead(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');
        $conversationId = $request->attribute('conversationId');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
        }

        if (!is_string($conversationId)) {
            return JsonResponse::create(['error' => 'Missing conversation ID'], 400);
        }

        $this->messagingService->markRead($conversationId, $userId);

        return JsonResponse::create(['status' => 'ok']);
    }
}
