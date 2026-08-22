<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Messaging;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A private message between forum users.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivateMessage
{
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $senderId,
        public string $body,
        public DateTimeImmutable $sentAt = new DateTimeImmutable(),
        public ?DateTimeImmutable $readAt = null,
        public bool $isDeleted = false,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
