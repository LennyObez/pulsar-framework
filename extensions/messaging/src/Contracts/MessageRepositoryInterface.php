<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Contracts;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Messaging\Domain\Message;

/**
 * Repository for encrypted message persistence.
 */
#[Api(since: '1.0.0')]
interface MessageRepositoryInterface
{
    /**
     * Find messages in a conversation with cursor-based pagination.
     *
     * @return PaginationResult<Message>
     */
    public function findByConversation(
        string $conversationId,
        int $page = 1,
        int $perPage = 50,
    ): PaginationResult;

    public function save(Message $message): void;

    public function delete(string $id): void;
}
