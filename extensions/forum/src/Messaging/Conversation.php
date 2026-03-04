<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Messaging;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function in_array;

/**
 * A conversation thread between two or more users.
 */
#[Api(since: '1.0.0')]
final readonly class Conversation
{
    /**
     * @param string $id Unique conversation identifier
     * @param list<string> $participantIds User IDs of all participants
     * @param string $subject Optional conversation subject line
     * @param int $messageCount Total number of messages in this conversation
     */
    public function __construct(
        public string $id,
        public array $participantIds,
        public string $subject = '',
        public int $messageCount = 0,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $lastMessageAt = new DateTimeImmutable(),
    ) {}

    /**
     * Check if a user is a participant.
     */
    public function hasParticipant(string $userId): bool
    {
        return in_array($userId, $this->participantIds, true);
    }
}
