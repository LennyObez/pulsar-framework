<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Poll;

use Pulsar\Api\Api;

/**
 * A single option in a poll.
 */
#[Api(since: '1.0.0')]
final readonly class PollOption
{
    public function __construct(
        public string $id,
        public string $text,
        public int $voteCount = 0,
    ) {}

    /**
     * Calculate the percentage of total votes this option received.
     */
    public function percentage(int $totalVotes): float
    {
        if ($totalVotes <= 0) {
            return 0.0;
        }

        return round(($this->voteCount / (float) $totalVotes) * 100, 1);
    }
}
