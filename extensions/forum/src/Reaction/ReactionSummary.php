<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Reaction;

use Pulsar\Api\Api;

/**
 * Aggregated reaction counts for a post.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReactionSummary
{
    /**
     * @param string $postId Post identifier
     * @param array<string, int> $counts Reaction type value => count
     * @param int $total Total number of reactions
     */
    public function __construct(
        public string $postId,
        public array $counts,
        public int $total,
    ) {}

    /**
     * Get count for a specific reaction type.
     */
    public function countFor(ReactionType $type): int
    {
        return $this->counts[$type->value] ?? 0;
    }
}
