<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Vote;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\PostVote;

final class PostVoteTest extends TestCase
{
    #[Test]
    public function castCreatesUpvote(): void
    {
        $vote = PostVote::cast(
            id: 'vote-1',
            userId: 'user-1',
            postId: 'post-1',
            value: VoteDirection::Up,
        );

        self::assertSame('vote-1', $vote->id);
        self::assertNull($vote->tenantId);
        self::assertSame('user-1', $vote->userId);
        self::assertSame('post-1', $vote->postId);
        self::assertSame(VoteDirection::Up, $vote->value);
        self::assertNotNull($vote->createdAt);
    }

    #[Test]
    public function castCreatesDownvote(): void
    {
        $vote = PostVote::cast(
            id: 'vote-2',
            userId: 'user-2',
            postId: 'post-1',
            value: VoteDirection::Down,
        );

        self::assertSame(VoteDirection::Down, $vote->value);
    }

    #[Test]
    public function castWithTenantId(): void
    {
        $vote = PostVote::cast(
            id: 'vote-1',
            userId: 'user-1',
            postId: 'post-1',
            value: VoteDirection::Up,
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $vote->tenantId);
    }
}
