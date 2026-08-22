<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A participant in a conversation, tracking membership and read state.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Participant
{
    public function __construct(
        public string $userId,
        public string $conversationId,
        public DateTimeImmutable $joinedAt,
        public ?DateTimeImmutable $lastReadAt,
    ) {}

    /**
     * Whether this participant has read all messages up to the given timestamp.
     */
    public function hasReadUpTo(DateTimeImmutable $messageTimestamp): bool
    {
        if ($this->lastReadAt === null) {
            return false;
        }

        return $this->lastReadAt >= $messageTimestamp;
    }
}
