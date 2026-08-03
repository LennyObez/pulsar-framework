<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Comments;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentBodyPolicy;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentService;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(CommentService::class)]
#[CoversClass(CommentBodyPolicy::class)]
final class CommentServiceTest extends TestCase
{
    private CommentRepositoryInterface&Stub $commentRepo;
    private ContentRepositoryInterface&Stub $contentRepo;
    private CommentBodyPolicy $bodyPolicy;
    private AuditLoggerInterface&Stub $auditLogger;
    private CommentService $service;

    protected function setUp(): void
    {
        $this->commentRepo = $this->createStub(CommentRepositoryInterface::class);
        $this->contentRepo = $this->createStub(ContentRepositoryInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $safeHtmlStub = $this->createStub(AuditLoggerInterface::class);
        $safeHtmlPolicy = new SafeHtmlPolicy($safeHtmlStub);
        $this->bodyPolicy = new CommentBodyPolicy($safeHtmlPolicy);

        $this->service = new CommentService(
            $this->commentRepo,
            $this->contentRepo,
            $this->bodyPolicy,
            $this->auditLogger,
        );
    }

    #[Test]
    public function submitAuthenticatedCommentReturnsComment(): void
    {
        $this->contentRepo->method('findById')->willReturn($this->createContentInstance());

        $comment = $this->service->submit(
            contentId: 'cnt-01',
            body: '<p>Great article</p>',
            authorId: 'user-42',
            guestName: null,
            guestEmail: null,
            ipHash: hash('sha256', '10.0.0.1'),
            userAgentHash: hash('sha256', 'Chrome/120'),
        );

        self::assertSame(ModerationStatus::Pending, $comment->status);
        self::assertSame('user-42', $comment->authorId);
        self::assertNull($comment->guestName);
    }

    #[Test]
    public function submitGuestCommentReturnsComment(): void
    {
        $this->contentRepo->method('findById')->willReturn($this->createContentInstance());

        $comment = $this->service->submit(
            contentId: 'cnt-01',
            body: '<p>Nice post</p>',
            authorId: null,
            guestName: 'Maria Gonzalez',
            guestEmail: 'maria.gonzalez@example.com',
            ipHash: hash('sha256', '10.0.0.2'),
            userAgentHash: hash('sha256', 'Firefox/119'),
        );

        self::assertNull($comment->authorId);
        self::assertSame('Maria Gonzalez', $comment->guestName);
    }

    #[Test]
    public function submitThrowsWhenContentNotFound(): void
    {
        $this->contentRepo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Content not found');

        $this->service->submit(
            contentId: 'nonexistent',
            body: 'body',
            authorId: null,
            guestName: 'Test',
            guestEmail: 'test@example.com',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );
    }

    #[Test]
    public function approveModeratesCommentToApproved(): void
    {
        $comment = Comment::create(
            id: 'c-01',
            contentId: 'cnt-01',
            body: 'body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->commentRepo->method('findById')->willReturn($comment);

        $approved = $this->service->approve('c-01', 'moderator-01', 'Looks good');

        self::assertSame(ModerationStatus::Approved, $approved->status);
    }

    #[Test]
    public function rejectModeratesCommentToRejected(): void
    {
        $comment = Comment::create(
            id: 'c-02',
            contentId: 'cnt-02',
            body: 'body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->commentRepo->method('findById')->willReturn($comment);

        $rejected = $this->service->reject('c-02', 'moderator-01', 'Off-topic');

        self::assertSame(ModerationStatus::Rejected, $rejected->status);
    }

    #[Test]
    public function markSpamModeratesCommentToSpam(): void
    {
        $comment = Comment::create(
            id: 'c-03',
            contentId: 'cnt-03',
            body: 'body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->commentRepo->method('findById')->willReturn($comment);

        $spam = $this->service->markSpam('c-03', 'moderator-01', 'Obvious spam');

        self::assertSame(ModerationStatus::Spam, $spam->status);
    }

    #[Test]
    public function moderateThrowsWhenCommentNotFound(): void
    {
        $this->commentRepo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Comment not found');

        $this->service->approve('nonexistent', 'moderator-01', 'test');
    }

    #[Test]
    public function editUpdatesCommentBody(): void
    {
        $comment = Comment::create(
            id: 'c-04',
            contentId: 'cnt-04',
            body: 'original body',
            ipHash: 'ip',
            userAgentHash: 'ua',
        );

        $this->commentRepo->method('findById')->willReturn($comment);

        $edited = $this->service->edit('c-04', '<p>Updated body</p>');

        self::assertStringContainsString('Updated body', $edited->body);
        self::assertNotNull($edited->editedAt);
    }

    #[Test]
    public function editThrowsWhenCommentNotFound(): void
    {
        $this->commentRepo->method('findById')->willReturn(null);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Comment not found');

        $this->service->edit('nonexistent', 'new body');
    }

    private function createContentInstance(): Content
    {
        return Content::create(
            id: 'cnt-01',
            contentType: ContentType::Article,
            authorId: 'author-01',
        );
    }
}
