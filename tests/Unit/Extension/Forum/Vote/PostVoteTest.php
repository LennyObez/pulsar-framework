<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Vote;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\PostVote;

use function time;

#[CoversClass(PostVote::class)]
final class PostVoteTest extends TestCase
{
    #[Test]
    public function castUpvote(): void
    {
        $vote = PostVote::cast(id: 'vote-001', userId: 'user-001', postId: 'post-001', value: VoteDirection::Up, tenantId: 'tenant-001');
        self::assertSame('vote-001', $vote->id);
        self::assertSame('tenant-001', $vote->tenantId);
        self::assertSame('user-001', $vote->userId);
        self::assertSame('post-001', $vote->postId);
        self::assertSame(VoteDirection::Up, $vote->value);
        self::assertEqualsWithDelta(time(), $vote->createdAt->getTimestamp(), 5);
    }

    #[Test]
    public function castDownvote(): void
    {
        $vote = PostVote::cast(id: 'vote-002', userId: 'user-002', postId: 'post-001', value: VoteDirection::Down);
        self::assertSame(VoteDirection::Down, $vote->value);
        self::assertNull($vote->tenantId);
    }

    #[Test]
    public function castWithoutTenant(): void
    {
        $vote = PostVote::cast(id: 'vote-001', userId: 'user-001', postId: 'post-001', value: VoteDirection::Up);
        self::assertNull($vote->tenantId);
    }

    #[Test]
    public function voteDirectionValues(): void
    {
        self::assertSame(1, VoteDirection::Up->value);
        self::assertSame(-1, VoteDirection::Down->value);
    }
}
