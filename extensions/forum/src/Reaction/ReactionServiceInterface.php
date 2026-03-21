<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Reaction;

use Pulsar\Api\Api;

/**
 * Service for managing emoji reactions on forum posts.
 * @api
 */
#[Api(since: '1.0.0')]
interface ReactionServiceInterface
{
    /**
     * Add a reaction to a post.
     *
     * If the user already has this reaction type on this post, this is a no-op.
     */
    public function addReaction(string $postId, string $userId, ReactionType $type): Reaction;

    /**
     * Remove a reaction from a post.
     */
    public function removeReaction(string $postId, string $userId, ReactionType $type): void;

    /**
     * Get aggregated reaction summary for a post.
     */
    public function getSummary(string $postId): ReactionSummary;

    /**
     * Get all reactions for a post.
     *
     * @return list<Reaction>
     */
    public function getReactions(string $postId): array;

    /**
     * Get a user's reactions on a post.
     *
     * @return list<ReactionType>
     */
    public function getUserReactions(string $postId, string $userId): array;
}
