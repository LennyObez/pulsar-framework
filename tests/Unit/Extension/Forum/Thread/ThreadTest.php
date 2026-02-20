<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Thread;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Thread\Thread;

#[CoversClass(Thread::class)]
final class ThreadTest extends TestCase
{
    #[Test]
    public function createReturnsOpenThreadWithDefaults(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );

        self::assertSame('thread-1', $thread->id);
        self::assertNull($thread->tenantId);
        self::assertSame('cat-1', $thread->categoryId);
        self::assertSame('user-1', $thread->authorId);
        self::assertSame('Test Thread', $thread->title);
        self::assertSame('test-thread', $thread->slug);
        self::assertSame(ThreadType::Discussion, $thread->type);
        self::assertSame(ThreadStatus::Open, $thread->status);
        self::assertFalse($thread->isPinned);
        self::assertFalse($thread->isLocked);
        self::assertNull($thread->solvedPostId);
        self::assertSame(0, $thread->replyCount);
        self::assertSame(0, $thread->viewCount);
        self::assertSame(0, $thread->voteScore);
        self::assertNull($thread->deletedAt);
        self::assertSame(1, $thread->version);
    }

    #[Test]
    public function createWithTenantId(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Tenant Thread',
            slug: 'tenant-thread',
            type: ThreadType::Question,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $thread->tenantId);
        self::assertSame(ThreadType::Question, $thread->type);
    }

    #[Test]
    public function lockSetsIsLockedTrue(): void
    {
        $thread = $this->createThread();
        $locked = $thread->lock();

        self::assertTrue($locked->isLocked);
        self::assertFalse($thread->isLocked);
    }

    #[Test]
    public function unlockSetsIsLockedFalse(): void
    {
        $thread = $this->createThread()->lock();
        $unlocked = $thread->unlock();

        self::assertFalse($unlocked->isLocked);
    }

    #[Test]
    public function pinSetsIsPinnedTrue(): void
    {
        $thread = $this->createThread();
        $pinned = $thread->pin();

        self::assertTrue($pinned->isPinned);
        self::assertFalse($thread->isPinned);
    }

    #[Test]
    public function unpinSetsIsPinnedFalse(): void
    {
        $thread = $this->createThread()->pin();
        $unpinned = $thread->unpin();

        self::assertFalse($unpinned->isPinned);
    }

    #[Test]
    public function incrementReplyCount(): void
    {
        $thread = $this->createThread();
        $incremented = $thread->incrementReplyCount();

        self::assertSame(1, $incremented->replyCount);
        self::assertSame(0, $thread->replyCount);
    }

    #[Test]
    public function incrementViewCount(): void
    {
        $thread = $this->createThread();
        $incremented = $thread->incrementViewCount();

        self::assertSame(1, $incremented->viewCount);
    }

    #[Test]
    public function updateVoteScore(): void
    {
        $thread = $this->createThread();
        $updated = $thread->updateVoteScore(5);

        self::assertSame(5, $updated->voteScore);
    }

    #[Test]
    public function updateVoteScoreNegative(): void
    {
        $thread = $this->createThread();
        $updated = $thread->updateVoteScore(-3);

        self::assertSame(-3, $updated->voteScore);
    }

    #[Test]
    public function solveSetsSolvedPostId(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Question',
            slug: 'question',
            type: ThreadType::Question,
            ipHash: 'h',
            userAgentHash: 'h',
        );

        $solved = $thread->solve('post-42');

        self::assertSame('post-42', $solved->solvedPostId);
        self::assertNull($thread->solvedPostId);
    }

    #[Test]
    public function isDeletedReturnsTrueWhenDeletedAtSet(): void
    {
        $thread = $this->createThread();
        self::assertFalse($thread->isDeleted());
    }

    #[Test]
    public function closeTransitionsToClosed(): void
    {
        $thread = $this->createThread();
        $closed = $thread->close();

        self::assertSame(ThreadStatus::Closed, $closed->status);
    }

    #[Test]
    public function reopenTransitionsToOpen(): void
    {
        $thread = $this->createThread()->close();
        $reopened = $thread->reopen();

        self::assertSame(ThreadStatus::Open, $reopened->status);
    }

    private function createThread(): Thread
    {
        return Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            ipHash: 'hash-ip',
            userAgentHash: 'hash-ua',
        );
    }
}
