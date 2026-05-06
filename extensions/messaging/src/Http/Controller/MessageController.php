<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Http\Controller;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Domain\MessageType;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_int;
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
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');
        /** @var mixed $conversationId */
        $conversationId = $request->getAttribute('conversationId');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        if (!is_string($conversationId)) {
            return Response::json(['error' => 'Missing conversation ID'], 400);
        }

        $conversation = $this->conversationRepository->findById($conversationId);

        if ($conversation === null || !$conversation->hasParticipant($userId)) {
            return Response::json(['error' => 'Conversation not found'], 404);
        }

        $queryParams = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $queryParams['page'] ?? null;
        $page = (is_int($rawPage) || is_string($rawPage)) ? (int) $rawPage : 1;
        /** @var mixed $rawPerPage */
        $rawPerPage = $queryParams['per_page'] ?? null;
        $perPage = (is_int($rawPerPage) || is_string($rawPerPage)) ? (int) $rawPerPage : 50;

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

        return Response::json([
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
    public function send(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');
        /** @var mixed $conversationId */
        $conversationId = $request->getAttribute('conversationId');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        if (!is_string($conversationId)) {
            return Response::json(['error' => 'Missing conversation ID'], 400);
        }

        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid request body'], 400);
        }

        /** @var mixed $encryptedContent */
        $encryptedContent = $body['encrypted_content'] ?? null;
        /** @var mixed $nonce */
        $nonce = $body['nonce'] ?? null;

        if (!is_string($encryptedContent) || !is_string($nonce)) {
            return Response::json([
                'error' => 'Missing required fields: encrypted_content, nonce',
            ], 400);
        }

        /** @var mixed $rawType */
        $rawType = $body['type'] ?? null;
        $type = MessageType::tryFrom(is_string($rawType) ? $rawType : 'text') ?? MessageType::Text;

        try {
            $message = $this->messagingService->sendMessage(
                $conversationId,
                $userId,
                $encryptedContent,
                $nonce,
                $type,
            );
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json([
            'id' => $message->id,
            'conversation_id' => $message->conversationId,
            'timestamp' => $message->timestamp->format('c'),
        ], 201);
    }

    /**
     * Mark conversation messages as read.
     */
    public function markRead(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');
        /** @var mixed $conversationId */
        $conversationId = $request->getAttribute('conversationId');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        if (!is_string($conversationId)) {
            return Response::json(['error' => 'Missing conversation ID'], 400);
        }

        $this->messagingService->markRead($conversationId, $userId);

        return Response::json(['status' => 'ok']);
    }
}
