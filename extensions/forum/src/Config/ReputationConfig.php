<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Reputation system configuration with point values and thresholds.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReputationConfig
{
    /**
     * @param int $pointsPerThread Points awarded for creating a thread
     * @param int $pointsPerPost Points awarded for creating a post/reply
     * @param int $pointsPerUpvote Points awarded when receiving an upvote
     * @param int $pointsPerDownvote Points deducted when receiving a downvote (negative)
     * @param int $pointsPerSolution Points awarded when a post is marked as solution
     * @param int $minReputationToDownvote Minimum reputation required to downvote
     */
    public function __construct(
        public int $pointsPerThread = 2,
        public int $pointsPerPost = 1,
        public int $pointsPerUpvote = 5,
        public int $pointsPerDownvote = -2,
        public int $pointsPerSolution = 15,
        public int $minReputationToDownvote = 50,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pointsPerThread: Coerce::int($data['points_per_thread'] ?? null, 2),
            pointsPerPost: Coerce::int($data['points_per_post'] ?? null, 1),
            pointsPerUpvote: Coerce::int($data['points_per_upvote'] ?? null, 5),
            pointsPerDownvote: Coerce::int($data['points_per_downvote'] ?? null, -2),
            pointsPerSolution: Coerce::int($data['points_per_solution'] ?? null, 15),
            minReputationToDownvote: Coerce::int($data['min_reputation_to_downvote'] ?? null, 50),
        );
    }
}
