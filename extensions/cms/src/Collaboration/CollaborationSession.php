<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Collaboration;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Represents a user's active collaboration session on a content item.
 *
 * Tracks cursor position and selection range for awareness (showing other
 * users' editing positions in the UI).
 *
 * @psalm-api Public DTO returned from CollaborationRepositoryInterface; consumed
 *            by CollaborationService and CRDT awareness rendering.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CollaborationSession
{
    public function __construct(
        public string $id,
        public string $contentId,
        public string $userId,
        public string $userName,
        public ?string $cursorPosition,
        public ?string $selectionRange,
        public DateTimeImmutable $connectedAt,
        public DateTimeImmutable $lastSeenAt,
    ) {}
}
