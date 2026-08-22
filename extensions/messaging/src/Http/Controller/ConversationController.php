<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Http\Controller;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Domain\ConversationType;
use Pulsar\Http\Message\Response;

use function in_array;
use function is_array;
use function is_string;

/**
 * REST API controller for conversation management.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConversationController
{
    public function __construct(
        private MessagingServiceInterface $messagingService,
        private ConversationRepositoryInterface $conversationRepository,
    ) {}

    /**
     * List conversations for the authenticated user.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $conversations = $this->conversationRepository->findByParticipant($userId);

        $items = [];
        foreach ($conversations as $conversation) {
            $items[] = [
                'id' => $conversation->id,
                'type' => $conversation->type->value,
                'title' => $conversation->title,
                'participant_count' => $conversation->participantCount(),
                'created_at' => $conversation->createdAt->format('c'),
                'updated_at' => $conversation->updatedAt->format('c'),
            ];
        }

        return Response::json(['conversations' => $items]);
    }

    /**
     * Create a new conversation.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');

        if (!is_string($userId)) {
            return Response::json(['error' => 'Authentication required'], 401);
        }

        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return Response::json(['error' => 'Invalid request body'], 400);
        }

        /** @var mixed $rawType */
        $rawType = $body['type'] ?? 'direct';
        $type = ConversationType::tryFrom(is_string($rawType) ? $rawType : 'direct');

        if ($type === null) {
            return Response::json(['error' => 'Invalid conversation type'], 400);
        }

        /** @var mixed $rawParticipantIds */
        $rawParticipantIds = $body['participant_ids'] ?? null;
        /** @var list<string> $participantIds */
        $participantIds = is_array($rawParticipantIds) ? $rawParticipantIds : [];

        // Ensure the creator is a participant
        if (!in_array($userId, $participantIds, true)) {
            $participantIds[] = $userId;
        }

        /** @var mixed $rawTitle */
        $rawTitle = $body['title'] ?? null;
        $title = is_string($rawTitle) ? $rawTitle : null;

        try {
            $conversation = $this->messagingService->createConversation($type, $participantIds, $title);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json([
            'id' => $conversation->id,
            'type' => $conversation->type->value,
            'title' => $conversation->title,
            'participant_ids' => $conversation->participantIds,
            'created_at' => $conversation->createdAt->format('c'),
        ], 201);
    }

    /**
     * Get a single conversation.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');
        /** @var mixed $conversationId */
        $conversationId = $request->getAttribute('id');

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

        return Response::json([
            'id' => $conversation->id,
            'type' => $conversation->type->value,
            'title' => $conversation->title,
            'participant_ids' => $conversation->participantIds,
            'created_at' => $conversation->createdAt->format('c'),
            'updated_at' => $conversation->updatedAt->format('c'),
        ]);
    }
}
