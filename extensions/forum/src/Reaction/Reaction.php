<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Reaction;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * An emoji reaction on a post.
 */
#[Api(since: '1.0.0')]
final readonly class Reaction
{
    public function __construct(
        public string $id,
        public string $postId,
        public string $userId,
        public ReactionType $type,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
