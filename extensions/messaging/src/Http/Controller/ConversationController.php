<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Http\Controller;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Domain\ConversationType;
use Pulsar\Http\JsonResponse;
use Pulsar\Http\RequestInterface;
use Pulsar\Http\ResponseInterface;

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
    public function index(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
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

        return JsonResponse::create(['conversations' => $items]);
    }

    /**
     * Create a new conversation.
     */
    public function create(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');

        if (!is_string($userId)) {
            return JsonResponse::create(['error' => 'Authentication required'], 401);
        }

        $body = $request->parsedBody();

        if (!is_array($body)) {
            return JsonResponse::create(['error' => 'Invalid request body'], 400);
        }

        $typeValue = $body['type'] ?? 'direct';
        $type = ConversationType::tryFrom((string) $typeValue);

        if ($type === null) {
            return JsonResponse::create(['error' => 'Invalid conversation type'], 400);
        }

        /** @var list<string> $participantIds */
        $participantIds = is_array($body['participant_ids'] ?? null) ? $body['participant_ids'] : [];

        // Ensure the creator is a participant
        if (!in_array($userId, $participantIds, true)) {
            $participantIds[] = $userId;
        }

        $title = is_string($body['title'] ?? null) ? $body['title'] : null;

        try {
            $conversation = $this->messagingService->createConversation($type, $participantIds, $title);
        } catch (InvalidArgumentException $e) {
            return JsonResponse::create(['error' => $e->getMessage()], 422);
        }

        return JsonResponse::create([
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
    public function show(RequestInterface $request): ResponseInterface
    {
        $userId = $request->attribute('user_id');
        $conversationId = $request->attribute('id');

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

        return JsonResponse::create([
            'id' => $conversation->id,
            'type' => $conversation->type->value,
            'title' => $conversation->title,
            'participant_ids' => $conversation->participantIds,
            'created_at' => $conversation->createdAt->format('c'),
            'updated_at' => $conversation->updatedAt->format('c'),
        ]);
    }
}
