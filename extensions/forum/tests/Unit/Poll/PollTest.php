<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Poll;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Poll\Poll;
use Pulsar\Extension\Forum\Poll\PollOption;
use Pulsar\Extension\Forum\Poll\PollVote;

final class PollTest extends TestCase
{
    #[Test]
    public function isOpenReturnsTrueWhenNoCloseDate(): void
    {
        $poll = new Poll(
            id: 'poll-1',
            threadId: 'thread-1',
            question: 'Favorite color?',
            options: [],
        );

        self::assertTrue($poll->isOpen());
    }

    #[Test]
    public function isOpenReturnsFalseWhenPastCloseDate(): void
    {
        $poll = new Poll(
            id: 'poll-1',
            threadId: 'thread-1',
            question: 'Favorite color?',
            options: [],
            closesAt: new DateTimeImmutable('-1 hour'),
        );

        self::assertFalse($poll->isOpen());
    }

    #[Test]
    public function isOpenReturnsTrueWhenFutureCloseDate(): void
    {
        $poll = new Poll(
            id: 'poll-1',
            threadId: 'thread-1',
            question: 'Favorite color?',
            options: [],
            closesAt: new DateTimeImmutable('+1 hour'),
        );

        self::assertTrue($poll->isOpen());
    }

    #[Test]
    public function pollOptionPercentageCalculatesCorrectly(): void
    {
        $option = new PollOption(id: 'opt-1', text: 'Red', voteCount: 30);

        self::assertSame(30.0, $option->percentage(100));
        self::assertSame(60.0, $option->percentage(50));
        self::assertSame(0.0, $option->percentage(0));
    }

    #[Test]
    public function pollOptionPercentageHandlesZeroTotal(): void
    {
        $option = new PollOption(id: 'opt-1', text: 'Blue', voteCount: 5);

        self::assertSame(0.0, $option->percentage(0));
        self::assertSame(0.0, $option->percentage(-1));
    }

    #[Test]
    public function pollVoteRecordsVoteData(): void
    {
        $vote = new PollVote(
            pollId: 'poll-1',
            optionId: 'opt-1',
            userId: 'user-1',
        );

        self::assertSame('poll-1', $vote->pollId);
        self::assertSame('opt-1', $vote->optionId);
        self::assertSame('user-1', $vote->userId);
    }

    #[Test]
    public function pollWithMultipleOptionsTracksVotes(): void
    {
        $poll = new Poll(
            id: 'poll-1',
            threadId: 'thread-1',
            question: 'Best framework?',
            options: [
                new PollOption('opt-1', 'Pulsar', 45),
                new PollOption('opt-2', 'Other', 15),
                new PollOption('opt-3', 'None', 5),
            ],
            totalVotes: 65,
        );

        self::assertCount(3, $poll->options);
        self::assertSame(65, $poll->totalVotes);
        self::assertSame(69.2, $poll->options[0]->percentage(65));
    }
}
