<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\VoteDirection;

/**
 * Dispatched when a user casts a vote on a thread or post.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VoteCast
{
    /**
     * @param string $targetType 'thread' or 'post'
     */
    public function __construct(
        public string $voteId,
        public string $targetType,
        public string $targetId,
        public string $voterId,
        public VoteDirection $direction,
        public string $targetAuthorId,
        public ?string $tenantId = null,
    ) {}
}
