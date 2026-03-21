<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for all forum errors.
 */
#[Api(since: '1.0.0')]
final class ForumException extends RuntimeException
{
    public static function notFound(string $entity, string $id): self
    {
        return new self("$entity not found: $id");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Invalid status transition from '$from' to '$to'");
    }

    public static function concurrencyConflict(string $entityId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrency conflict for entity %s: expected version %d was already modified',
            $entityId,
            $expectedVersion,
        ));
    }

    public static function unauthorized(string $action): self
    {
        return new self("Unauthorized forum action: $action");
    }

    public static function banned(string $userId): self
    {
        return new self("User is banned from the forum: $userId");
    }

    public static function rateLimited(string $action, int $cooldownSeconds): self
    {
        return new self(sprintf(
            'Rate limited: %s: please wait %d seconds',
            $action,
            $cooldownSeconds,
        ));
    }

    public static function duplicateVote(string $userId, string $targetId): self
    {
        return new self("User $userId has already voted on: $targetId");
    }

    public static function alreadyResolved(string $threadId): self
    {
        return new self("Thread already has an accepted solution: $threadId");
    }

    public static function editWindowExpired(string $postId): self
    {
        return new self("Edit window has expired for post: $postId");
    }

    public static function threadLocked(string $threadId): self
    {
        return new self("Thread is locked and does not accept replies: $threadId");
    }

    public static function categoryLocked(string $categoryId): self
    {
        return new self("Category is locked and does not accept new threads: $categoryId");
    }

    public static function selfVote(): self
    {
        return new self('You cannot vote on your own content');
    }

    public static function insufficientReputation(int $required): self
    {
        return new self(sprintf('Insufficient reputation to downvote: %d required', $required));
    }

    public static function duplicateReport(string $userId, string $targetId): self
    {
        return new self("User $userId already has a pending report for: $targetId");
    }
}
