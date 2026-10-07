<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Comments;

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
    public function createGuestCommentHasPendingStatus(): void
    {
        $comment = Comment::create(
            id: '0194d4e0-aaaa-7000-bbbb-000000000001',
            contentId: '0194d4e0-aaaa-7000-bbbb-000000000002',
            body: '<p>Great article on PHP patterns!</p>',
            ipHash: hash('sha256', '192.168.1.100'),
            userAgentHash: hash('sha256', 'Mozilla/5.0'),
            guestName: 'Thomas Muller',
            guestEmail: 'thomas.muller@example.com',
        );

        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertNull($comment->authorId);
        self::assertSame('Thomas Muller', $comment->guestName);
        self::assertSame('thomas.muller@example.com', $comment->guestEmail);
        self::assertNull($comment->tenantId);
        self::assertNull($comment->parentId);
        self::assertNull($comment->editedAt);
        self::assertNotNull($comment->editWindowExpiresAt);
        self::assertSame(DataClassification::Pii, $comment->dataClassification);
        self::assertNull($comment->deletedAt);
        self::assertTrue($comment->isPending());
        self::assertFalse($comment->isApproved());
        self::assertFalse($comment->isDeleted());
    }

    #[Test]
    public function createGuestCommentWithTenantAndParent(): void
    {
        $comment = Comment::create(
            id: '0194d4e0-aaaa-7000-bbbb-000000000003',
            contentId: '0194d4e0-aaaa-7000-bbbb-000000000004',
            body: 'Reply to the parent comment',
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
            tenantId: 'tenant-01',
            parentId: '0194d4e0-aaaa-7000-bbbb-000000000001',
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame('tenant-01', $comment->tenantId);
        self::assertSame('0194d4e0-aaaa-7000-bbbb-000000000001', $comment->parentId);
        self::assertSame(DataClassification::Confidential, $comment->dataClassification);
    }

    #[Test]
    public function createAuthenticatedCommentPendingByDefault(): void
    {
        $comment = Comment::createAuthenticated(
            id: '0194d4e0-aaaa-7000-bbbb-000000000005',
            contentId: '0194d4e0-aaaa-7000-bbbb-000000000006',
            authorId: 'user-42',
            body: 'Authenticated comment content',
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
        );

        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertSame('user-42', $comment->authorId);
        self::assertNull($comment->guestName);
        self::assertNull($comment->guestEmail);
    }

    #[Test]
    public function createAuthenticatedCommentAutoApproved(): void
    {
        $comment = Comment::createAuthenticated(
            id: '0194d4e0-aaaa-7000-bbbb-000000000007',
            contentId: '0194d4e0-aaaa-7000-bbbb-000000000008',
            authorId: 'user-admin',
            body: 'Auto-approved admin comment',
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
            autoApprove: true,
        );

        self::assertSame(ModerationStatus::Approved, $comment->status);
        self::assertTrue($comment->isApproved());
        self::assertFalse($comment->isPending());
    }

    #[Test]
    public function createAuthenticatedWithTenantAndParent(): void
    {
        $comment = Comment::createAuthenticated(
            id: '0194d4e0-aaaa-7000-bbbb-000000000009',
            contentId: '0194d4e0-aaaa-7000-bbbb-000000000010',
            authorId: 'user-99',
            body: 'Threaded reply',
            ipHash: 'ip-hash',
            userAgentHash: 'ua-hash',
            tenantId: 'tenant-02',
            parentId: '0194d4e0-aaaa-7000-bbbb-000000000005',
            dataClassification: DataClassification::Internal,
        );

        self::assertSame('tenant-02', $comment->tenantId);
        self::assertSame('0194d4e0-aaaa-7000-bbbb-000000000005', $comment->parentId);
        self::assertSame(DataClassification::Internal, $comment->dataClassification);
    }

    #[Test]
    public function moderatePendingToApproved(): void
    {
        $comment = Comment::create(
            id: 'c-01',
            contentId: 'cnt-01',
            body: 'test body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $approved = $comment->moderate(ModerationStatus::Approved);

        self::assertSame(ModerationStatus::Approved, $approved->status);
        self::assertSame($comment->id, $approved->id);
        self::assertSame($comment->body, $approved->body);
    }

    #[Test]
    public function moderatePendingToRejected(): void
    {
        $comment = Comment::create(
            id: 'c-02',
            contentId: 'cnt-02',
            body: 'test body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $rejected = $comment->moderate(ModerationStatus::Rejected);

        self::assertSame(ModerationStatus::Rejected, $rejected->status);
    }

    #[Test]
    public function moderatePendingToSpam(): void
    {
        $comment = Comment::create(
            id: 'c-03',
            contentId: 'cnt-03',
            body: 'spam body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $spam = $comment->moderate(ModerationStatus::Spam);

        self::assertSame(ModerationStatus::Spam, $spam->status);
    }

    #[Test]
    public function moderateApprovedToRejectedThrows(): void
    {
        $comment = Comment::createAuthenticated(
            id: 'c-04',
            contentId: 'cnt-04',
            authorId: 'user-01',
            body: 'approved body',
            ipHash: 'ip',
            userAgentHash: 'ua',
            autoApprove: true,
        );

        self::assertSame(ModerationStatus::Approved, $comment->status);

        $this->expectException(CmsException::class);
        $comment->moderate(ModerationStatus::Rejected);
    }

    #[Test]
    public function moderateSameStatusThrows(): void
    {
        $comment = Comment::create(
            id: 'c-05',
            contentId: 'cnt-05',
            body: 'test',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->expectException(CmsException::class);
        $comment->moderate(ModerationStatus::Pending);
    }

    #[Test]
    public function editWithinWindowSucceeds(): void
    {
        $comment = Comment::create(
            id: 'c-06',
            contentId: 'cnt-06',
            body: 'original body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $edited = $comment->edit('updated body');

        self::assertSame('updated body', $edited->body);
        self::assertNotNull($edited->editedAt);
        self::assertSame($comment->editWindowExpiresAt, $edited->editWindowExpiresAt);
    }

    #[Test]
    public function editAfterWindowExpiresThrows(): void
    {
        $comment = new Comment(
            id: 'c-07',
            tenantId: null,
            contentId: 'cnt-07',
            parentId: null,
            authorId: null,
            guestName: 'Guest',
            guestEmail: 'guest@example.com',
            body: 'old body',
            status: ModerationStatus::Pending,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: new DateTimeImmutable('-1 hour'),
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable('-2 hours'),
            deletedAt: null,
        );

        self::assertFalse($comment->canEdit());

        $this->expectException(CmsException::class);
        $comment->edit('new body');
    }

    #[Test]
    public function canEditReturnsFalseWhenEditWindowNull(): void
    {
        $comment = new Comment(
            id: 'c-08',
            tenantId: null,
            contentId: 'cnt-08',
            parentId: null,
            authorId: 'user-01',
            guestName: null,
            guestEmail: null,
            body: 'body',
            status: ModerationStatus::Approved,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable(),
            deletedAt: null,
        );

        self::assertFalse($comment->canEdit());
    }

    #[Test]
    public function isDeletedReturnsTrueWhenDeletedAtSet(): void
    {
        $comment = new Comment(
            id: 'c-09',
            tenantId: null,
            contentId: 'cnt-09',
            parentId: null,
            authorId: null,
            guestName: null,
            guestEmail: null,
            body: 'deleted body',
            status: ModerationStatus::Approved,
            ipHash: 'ip',
            userAgentHash: 'ua',
            editedAt: null,
            editWindowExpiresAt: null,
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable('-1 day'),
            deletedAt: new DateTimeImmutable(),
        );

        self::assertTrue($comment->isDeleted());
    }
}
