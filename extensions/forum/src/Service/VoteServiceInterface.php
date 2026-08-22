<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\PostVote;
use Pulsar\Extension\Forum\Vote\ThreadVote;

/**
 * Voting service: cast and remove votes on threads and posts.
 * @api
 */
#[Api(since: '1.0.0')]
interface VoteServiceInterface
{
    public function castThreadVote(
        string $userId,
        string $threadId,
        VoteDirection $direction,
        ?string $tenantId = null,
    ): ThreadVote;

    public function castPostVote(
        string $userId,
        string $postId,
        VoteDirection $direction,
        ?string $tenantId = null,
    ): PostVote;

    public function removeThreadVote(string $userId, string $threadId): void;

    public function removePostVote(string $userId, string $postId): void;
}
