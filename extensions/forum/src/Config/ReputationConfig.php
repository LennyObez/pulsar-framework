<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;

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
     * @param array{
     *     points_per_thread?: int,
     *     points_per_post?: int,
     *     points_per_upvote?: int,
     *     points_per_downvote?: int,
     *     points_per_solution?: int,
     *     min_reputation_to_downvote?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pointsPerThread: $data['points_per_thread'] ?? 2,
            pointsPerPost: $data['points_per_post'] ?? 1,
            pointsPerUpvote: $data['points_per_upvote'] ?? 5,
            pointsPerDownvote: $data['points_per_downvote'] ?? -2,
            pointsPerSolution: $data['points_per_solution'] ?? 15,
            minReputationToDownvote: $data['min_reputation_to_downvote'] ?? 50,
        );
    }
}
