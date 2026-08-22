<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Vote;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\VoteDirection;

/**
 * A user's vote on a post.
 *
 * Unique per (tenant, user, post): enforced at the repository/DB level.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PostVote
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $userId UUIDv7 FK user
     * @param string $postId UUIDv7 FK post
     * @param VoteDirection $value Vote direction
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $userId,
        public string $postId,
        public VoteDirection $value,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Cast a new vote on a post.
     */
    public static function cast(
        string $id,
        string $userId,
        string $postId,
        VoteDirection $value,
        ?string $tenantId = null,
    ): self {
        return new self(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            postId: $postId,
            value: $value,
            createdAt: new DateTimeImmutable(),
        );
    }
}
