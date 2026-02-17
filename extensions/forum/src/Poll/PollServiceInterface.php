<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Poll;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Api;

/**
 * Service for creating and managing polls in forum threads.
 */
#[Api(since: '1.0.0')]
interface PollServiceInterface
{
    /**
     * Create a poll attached to a thread.
     *
     * @param list<string> $optionTexts Option text values
     */
    public function create(
        string $threadId,
        string $question,
        array $optionTexts,
        bool $allowMultiple = false,
        bool $showResultsBeforeClose = true,
        ?DateTimeImmutable $closesAt = null,
    ): Poll;

    /**
     * Get the poll for a thread.
     */
    public function getForThread(string $threadId): ?Poll;

    /**
     * Cast a vote on a poll option.
     *
     * @throws InvalidArgumentException If poll is closed or user already voted (single-choice)
     */
    public function vote(string $pollId, string $optionId, string $userId): PollVote;

    /**
     * Remove a user's vote.
     */
    public function removeVote(string $pollId, string $userId, string $optionId): void;

    /**
     * Check if a user has voted on a poll.
     *
     * @return list<string> Option IDs the user voted for
     */
    public function getUserVotes(string $pollId, string $userId): array;

    /**
     * Close a poll to prevent further voting.
     */
    public function close(string $pollId): void;
}
