<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Domain\Conversation;

/**
 * Repository for conversation persistence.
 */
#[Api(since: '1.0.0')]
interface ConversationRepositoryInterface
{
    public function findById(string $id): ?Conversation;

    /**
     * Find all conversations a user participates in.
     *
     * @return list<Conversation>
     */
    public function findByParticipant(string $userId): array;

    /**
     * Find a direct conversation between exactly two users.
     */
    public function findDirect(string $userIdA, string $userIdB): ?Conversation;

    public function save(Conversation $conversation): void;

    public function delete(string $id): void;
}
