<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Thread;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Thread\Thread;

final class ThreadTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
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
            title: 'Title',
            slug: 'title',
            type: ThreadType::Question,
            ipHash: 'ip',
            userAgentHash: 'ua',
            tenantId: 'tenant-1',
        );

        self::assertSame('tenant-1', $thread->tenantId);
    }

    #[Test]
    public function editTitleChangesFields(): void
    {
        $thread = $this->createOpenThread();
        $updated = $thread->editTitle('New Title', 'new-title');

        self::assertSame('New Title', $updated->title);
        self::assertSame('new-title', $updated->slug);
        self::assertNotSame($thread, $updated);
    }

    #[Test]
    public function moveToCategoryChangesCategory(): void
    {
        $thread = $this->createOpenThread();
        $moved = $thread->moveToCategory('cat-2');

        self::assertSame('cat-2', $moved->categoryId);
        self::assertSame('cat-1', $thread->categoryId);
    }

    #[Test]
    public function closeTransitionsFromOpen(): void
    {
        $thread = $this->createOpenThread();
        $closed = $thread->close();

        self::assertSame(ThreadStatus::Closed, $closed->status);
    }

    #[Test]
    public function closeThrowsOnInvalidTransition(): void
    {
        $thread = $this->createOpenThread();
        $closed = $thread->close();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Invalid status transition');
        $closed->close();
    }

    #[Test]
    public function reopenTransitionsFromClosed(): void
    {
        $thread = $this->createOpenThread();
        $closed = $thread->close();
        $reopened = $closed->reopen();

        self::assertSame(ThreadStatus::Open, $reopened->status);
        self::assertFalse($reopened->isLocked);
    }

    #[Test]
    public function reopenThrowsFromOpen(): void
    {
        $thread = $this->createOpenThread();

        $this->expectException(ForumException::class);
        $thread->reopen();
    }

    #[Test]
    public function lockSetsFlag(): void
    {
        $thread = $this->createOpenThread();
        $locked = $thread->lock();

        self::assertTrue($locked->isLocked);
    }

    #[Test]
    public function unlockClearsFlag(): void
    {
        $thread = $this->createOpenThread();
        $locked = $thread->lock();
        $unlocked = $locked->unlock();

        self::assertFalse($unlocked->isLocked);
    }

    #[Test]
    public function pinSetsFlag(): void
    {
        $thread = $this->createOpenThread();
        $pinned = $thread->pin();

        self::assertTrue($pinned->isPinned);
    }

    #[Test]
    public function unpinClearsFlag(): void
    {
        $thread = $this->createOpenThread();
        $pinned = $thread->pin();
        $unpinned = $pinned->unpin();

        self::assertFalse($unpinned->isPinned);
    }

    #[Test]
    public function solveMarksQuestionThread(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Question',
            slug: 'question',
            type: ThreadType::Question,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $solved = $thread->solve('post-1');

        self::assertSame('post-1', $solved->solvedPostId);
    }

    #[Test]
    public function solveThrowsForDiscussionType(): void
    {
        $thread = $this->createOpenThread();

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('Invalid status transition');
        $thread->solve('post-1');
    }

    #[Test]
    public function solveThrowsWhenAlreadySolved(): void
    {
        $thread = Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Q',
            slug: 'q',
            type: ThreadType::Question,
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
        $solved = $thread->solve('post-1');

        $this->expectException(ForumException::class);
        $this->expectExceptionMessage('already has an accepted solution');
        $solved->solve('post-2');
    }

    #[Test]
    public function incrementReplyCount(): void
    {
        $thread = $this->createOpenThread();
        $updated = $thread->incrementReplyCount();

        self::assertSame(1, $updated->replyCount);
    }

    #[Test]
    public function incrementViewCount(): void
    {
        $thread = $this->createOpenThread();
        $updated = $thread->incrementViewCount();

        self::assertSame(1, $updated->viewCount);
    }

    #[Test]
    public function updateVoteScore(): void
    {
        $thread = $this->createOpenThread();

        $upvoted = $thread->updateVoteScore(1);
        self::assertSame(1, $upvoted->voteScore);

        $downvoted = $upvoted->updateVoteScore(-2);
        self::assertSame(-1, $downvoted->voteScore);
    }

    #[Test]
    public function isOpenReturnsTrue(): void
    {
        $thread = $this->createOpenThread();
        self::assertTrue($thread->isOpen());
    }

    #[Test]
    public function isOpenReturnsFalseWhenClosed(): void
    {
        $thread = $this->createOpenThread()->close();
        self::assertFalse($thread->isOpen());
    }

    #[Test]
    public function isSolvedReturnsFalseByDefault(): void
    {
        $thread = $this->createOpenThread();
        self::assertFalse($thread->isSolved());
    }

    #[Test]
    public function isDeletedReturnsFalseByDefault(): void
    {
        $thread = $this->createOpenThread();
        self::assertFalse($thread->isDeleted());
    }

    #[Test]
    public function isDeletedReturnsTrueWhenDeletedAtSet(): void
    {
        $thread = new Thread(
            id: 't',
            tenantId: null,
            categoryId: 'c',
            authorId: 'u',
            title: 'T',
            slug: 's',
            type: ThreadType::Discussion,
            status: ThreadStatus::Open,
            isPinned: false,
            isLocked: false,
            solvedPostId: null,
            replyCount: 0,
            viewCount: 0,
            voteScore: 0,
            lastActivityAt: null,
            ipHash: 'ip',
            userAgentHash: 'ua',
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: new DateTimeImmutable(),
        );

        self::assertTrue($thread->isDeleted());
    }

    private function createOpenThread(): Thread
    {
        return Thread::create(
            id: 'thread-1',
            categoryId: 'cat-1',
            authorId: 'user-1',
            title: 'Test Thread',
            slug: 'test-thread',
            type: ThreadType::Discussion,
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
        );
    }
}
