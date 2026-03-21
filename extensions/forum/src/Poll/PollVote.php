<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Poll;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A user's vote on a poll option.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PollVote
{
    public function __construct(
        public string $pollId,
        public string $optionId,
        public string $userId,
        public DateTimeImmutable $votedAt = new DateTimeImmutable(),
    ) {}
}
