<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Comments;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(Comment::class)]
final class CommentTest extends TestCase
{
    #[Test]
    public function create_returns_pending_guest_comment(): void
    {
        $comment = Comment::create(
            id: 'comment-1',
            contentId: 'content-1',
            body: '<p>Hello world</p>',
            ipHash: 'iphash123',
            userAgentHash: 'uahash456',
            guestName: 'Jane',
            guestEmail: 'jane@example.com',
        );

        self::assertSame('comment-1', $comment->id);
        self::assertSame('content-1', $comment->contentId);
        self::assertSame('<p>Hello world</p>', $comment->body);
        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertNull($comment->authorId);
        self::assertSame('Jane', $comment->guestName);
        self::assertSame('jane@example.com', $comment->guestEmail);
        self::assertNull($comment->parentId);
        self::assertNull($comment->tenantId);
        self::assertNull($comment->editedAt);
        self::assertNotNull($comment->editWindowExpiresAt);
        self::assertNull($comment->deletedAt);
        self::assertSame(DataClassification::Pii, $comment->dataClassification);
    }

    #[Test]
    public function create_sets_edit_window_15_minutes_in_future(): void
    {
        $before = new DateTimeImmutable('+14 minutes');
        $comment = Comment::create('c1', 'content-1', 'body', 'ip', 'ua');
        $after = new DateTimeImmutable('+16 minutes');

        self::assertGreaterThan($before, $comment->editWindowExpiresAt);
        self::assertLessThan($after, $comment->editWindowExpiresAt);
    }

    #[Test]
    public function createAuthenticated_returns_pending_by_default(): void
    {
        $comment = Comment::createAuthenticated(
            id: 'c1',
            contentId: 'content-1',
            authorId: 'author-1',
            body: '<p>Auth comment</p>',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        self::assertSame('author-1', $comment->authorId);
        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertNull($comment->guestName);
        self::assertNull($comment->guestEmail);
    }

    #[Test]
    public function createAuthenticated_auto_approves_when_flag_set(): void
    {
        $comment = Comment::createAuthenticated(
            id: 'c1',
            contentId: 'content-1',
            authorId: 'author-1',
            body: 'body',
            ipHash: 'ip',
            userAgentHash: 'ua',
            autoApprove: true,
        );

        self::assertSame(ModerationStatus::Approved, $comment->status);
    }

    #[Test]
    public function moderate_transitions_pending_to_approved(): void
    {
        $comment = Comment::create('c1', 'content-1', 'body', 'ip', 'ua');
        $moderated = $comment->moderate(ModerationStatus::Approved);

        self::assertSame(ModerationStatus::Approved, $moderated->status);
        self::assertSame($comment->body, $moderated->body);
    }

    #[Test]
    public function moderate_transitions_pending_to_rejected(): void
    {
        $comment = Comment::create('c1', 'content-1', 'body', 'ip', 'ua');
        $moderated = $comment->moderate(ModerationStatus::Rejected);

        self::assertSame(ModerationStatus::Rejected, $moderated->status);
    }

    #[Test]
    public function moderate_transitions_pending_to_spam(): void
    {
        $comment = Comment::create('c1', 'content-1', 'body', 'ip', 'ua');
        $moderated = $comment->moderate(ModerationStatus::Spam);

        self::assertSame(ModerationStatus::Spam, $moderated->status);
    }

    #[Test]
    public function moderate_throws_for_non_pending_comment(): void
    {
        $comment = Comment::create('c1', 'content-1', 'body', 'ip', 'ua')
            ->moderate(ModerationStatus::Approved);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage("Invalid status transition from 'approved' to 'rejected'");

        $comment->moderate(ModerationStatus::Rejected);
    }

    #[Test]
    public function edit_updates_body_and_sets_editedAt(): void
    {
        $comment = Comment::create('c1', 'content-1', 'original', 'ip', 'ua');
        $edited = $comment->edit('updated body');

        self::assertSame('updated body', $edited->body);
        self::assertNotNull($edited->editedAt);
        self::assertSame($comment->id, $edited->id);
    }

    #[Test]
    public function edit_throws_when_window_expired(): void
    {
        $now = new DateTimeImmutable();
        $expired = new DateTimeImmutable('-1 hour');
        $comment = new Comment(
            id: 'c1',
            tenantId: null,
            contentId: 'content-1',
            parentId: null,
            authorId: null,
            guestName: null,
            guestEmail: null,
            body: 'body',
            status: ModerationStatus::Pending,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: $expired,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            deletedAt: null,
        );

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Edit window has expired');

        $comment->edit('new body');
    }

    #[Test]
    public function edit_throws_when_window_is_null(): void
    {
        $now = new DateTimeImmutable();
        $comment = new Comment(
            id: 'c1',
            tenantId: null,
            contentId: 'content-1',
            parentId: null,
            authorId: null,
            guestName: null,
            guestEmail: null,
            body: 'body',
            status: ModerationStatus::Pending,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            deletedAt: null,
        );

        $this->expectException(CmsException::class);

        $comment->edit('new body');
    }

    #[Test]
    public function status_helpers(): void
    {
        $pending = Comment::create('c1', 'content-1', 'body', 'ip', 'ua');
        self::assertTrue($pending->isPending());
        self::assertFalse($pending->isApproved());
        self::assertFalse($pending->isDeleted());

        $approved = $pending->moderate(ModerationStatus::Approved);
        self::assertFalse($approved->isPending());
        self::assertTrue($approved->isApproved());
    }

    #[Test]
    public function isDeleted_returns_true_when_deletedAt_set(): void
    {
        $now = new DateTimeImmutable();
        $comment = new Comment(
            id: 'c1',
            tenantId: null,
            contentId: 'content-1',
            parentId: null,
            authorId: null,
            guestName: null,
            guestEmail: null,
            body: 'body',
            status: ModerationStatus::Pending,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            deletedAt: $now,
        );

        self::assertTrue($comment->isDeleted());
    }
}
