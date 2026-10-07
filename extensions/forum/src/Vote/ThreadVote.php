<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Vote;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\VoteDirection;

/**
 * A user's vote on a thread.
 *
 * Unique per (tenant, user, thread): enforced at the repository/DB level.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreadVote
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $userId UUIDv7 FK user
     * @param string $threadId UUIDv7 FK thread
     * @param VoteDirection $value Vote direction
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $userId,
        public string $threadId,
        public VoteDirection $value,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Cast a new vote on a thread.
     */
    public static function cast(
        string $id,
        string $userId,
        string $threadId,
        VoteDirection $value,
        ?string $tenantId = null,
    ): self {
        return new self(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            threadId: $threadId,
            value: $value,
            createdAt: new DateTimeImmutable(),
        );
    }
}
