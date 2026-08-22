<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Account;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ReputationLevel;

/**
 * Forum statistics for a user, displayed in the account section header.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumAccountStats
{
    public function __construct(
        public int $threadCount,
        public int $replyCount,
        public int $bestAnswerCount,
        public int $reputation,
        public ReputationLevel $rank,
        public DateTimeImmutable $joinedAt,
    ) {}
}
