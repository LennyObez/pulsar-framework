<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Subscription;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Thread subscription: indicates a user wants notifications for new replies.
 *
 * Unique per (tenant, user, thread): enforced at the repository/DB level.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThreadSubscription
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $userId UUIDv7 FK auth_users
     * @param string $threadId UUIDv7 FK thread
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $userId,
        public string $threadId,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Subscribe a user to a thread.
     */
    public static function subscribe(
        string $id,
        string $userId,
        string $threadId,
        ?string $tenantId = null,
    ): self {
        return new self(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            threadId: $threadId,
            createdAt: new DateTimeImmutable(),
        );
    }
}
