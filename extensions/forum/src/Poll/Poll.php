<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Poll;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A poll attached to a forum thread.
 */
#[Api(since: '1.0.0')]
final readonly class Poll
{
    /**
     * @param string $id Unique poll identifier
     * @param string $threadId Thread this poll belongs to
     * @param string $question The poll question
     * @param list<PollOption> $options Available options
     * @param bool $allowMultiple Whether voters can select multiple options
     * @param bool $showResultsBeforeClose Whether results are visible before poll closes
     * @param int $totalVotes Total number of votes cast
     */
    public function __construct(
        public string $id,
        public string $threadId,
        public string $question,
        public array $options,
        public bool $allowMultiple = false,
        public bool $showResultsBeforeClose = true,
        public int $totalVotes = 0,
        public ?DateTimeImmutable $closesAt = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}

    /**
     * Whether the poll is still accepting votes.
     */
    public function isOpen(): bool
    {
        if ($this->closesAt === null) {
            return true;
        }

        return $this->closesAt > new DateTimeImmutable();
    }
}
