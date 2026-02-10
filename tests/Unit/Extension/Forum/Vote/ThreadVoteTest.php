<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Vote;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\ThreadVote;

use function time;

#[CoversClass(ThreadVote::class)]
final class ThreadVoteTest extends TestCase
{
    #[Test]
    public function castUpvote(): void
    {
        $vote = ThreadVote::cast(id: 'vote-001', userId: 'user-001', threadId: 'thread-001', value: VoteDirection::Up, tenantId: 'tenant-001');
        self::assertSame('vote-001', $vote->id);
        self::assertSame('tenant-001', $vote->tenantId);
        self::assertSame('user-001', $vote->userId);
        self::assertSame('thread-001', $vote->threadId);
        self::assertSame(VoteDirection::Up, $vote->value);
        self::assertEqualsWithDelta(time(), $vote->createdAt->getTimestamp(), 5);
    }

    #[Test]
    public function castDownvote(): void
    {
        $vote = ThreadVote::cast(id: 'vote-002', userId: 'user-002', threadId: 'thread-001', value: VoteDirection::Down);
        self::assertSame(VoteDirection::Down, $vote->value);
        self::assertNull($vote->tenantId);
    }

    #[Test]
    public function castWithoutTenant(): void
    {
        $vote = ThreadVote::cast(id: 'vote-001', userId: 'user-001', threadId: 'thread-001', value: VoteDirection::Up);
        self::assertNull($vote->tenantId);
    }
}
