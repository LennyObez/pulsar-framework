<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function count;
use function in_array;

/**
 * A messaging conversation (direct, group, or channel).
 */
#[Api(since: '1.0.0')]
final readonly class Conversation
{
    /**
     * @param string $id Unique conversation identifier
     * @param ConversationType $type Conversation type
     * @param list<string> $participantIds User IDs of participants
     * @param string|null $title Optional title (required for group/channel)
     * @param DateTimeImmutable $createdAt When the conversation was created
     * @param DateTimeImmutable $updatedAt When the conversation was last updated
     */
    public function __construct(
        public string $id,
        public ConversationType $type,
        public array $participantIds,
        public ?string $title,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function hasParticipant(string $userId): bool
    {
        return in_array($userId, $this->participantIds, true);
    }

    public function participantCount(): int
    {
        return count($this->participantIds);
    }
}
