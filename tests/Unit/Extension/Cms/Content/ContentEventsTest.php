<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Event\CmsReady;
use Pulsar\Extension\Cms\Content\Event\CommentReceived;
use Pulsar\Extension\Cms\Content\Event\ContentArchived;
use Pulsar\Extension\Cms\Content\Event\ContentCreated;
use Pulsar\Extension\Cms\Content\Event\ContentDeleted;
use Pulsar\Extension\Cms\Content\Event\ContentPublished;
use Pulsar\Extension\Cms\Content\Event\ContentRestored;
use Pulsar\Extension\Cms\Content\Event\ContentUpdated;
use Pulsar\Extension\Cms\Content\Event\ReviewRequested;
use Pulsar\Extension\Cms\Content\Event\RevisionCreated;
use Pulsar\Extension\Cms\Content\Event\SlugChanged;

#[CoversClass(CmsReady::class)]
#[CoversClass(CommentReceived::class)]
#[CoversClass(ContentArchived::class)]
#[CoversClass(ContentCreated::class)]
#[CoversClass(ContentDeleted::class)]
#[CoversClass(ContentPublished::class)]
#[CoversClass(ContentRestored::class)]
#[CoversClass(ContentUpdated::class)]
#[CoversClass(ReviewRequested::class)]
#[CoversClass(RevisionCreated::class)]
#[CoversClass(SlugChanged::class)]
final class ContentEventsTest extends TestCase
{
    #[Test]
    public function cmsReadyEvent(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');
        $event = new CmsReady(timestamp: $now);

        self::assertSame($now, $event->timestamp);
    }

    #[Test]
    public function commentReceivedEvent(): void
    {
        $event = new CommentReceived(
            contentId: 'cnt-01',
            contentTitle: 'PHP Security Guide',
            commentId: 'comment-01',
            authorName: 'Jane Doe',
            commentBody: 'Great article!',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('PHP Security Guide', $event->contentTitle);
        self::assertSame('comment-01', $event->commentId);
        self::assertSame('Jane Doe', $event->authorName);
        self::assertSame('Great article!', $event->commentBody);
    }

    #[Test]
    public function contentArchivedEvent(): void
    {
        $event = new ContentArchived(
            contentId: 'cnt-01',
            archivedBy: 'user-editor',
            reason: 'Outdated content',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('user-editor', $event->archivedBy);
        self::assertSame('Outdated content', $event->reason);
    }

    #[Test]
    public function contentArchivedEventWithoutReason(): void
    {
        $event = new ContentArchived(
            contentId: 'cnt-02',
            archivedBy: 'user-admin',
            reason: null,
        );

        self::assertNull($event->reason);
    }

    #[Test]
    public function contentCreatedEvent(): void
    {
        $event = new ContentCreated(
            contentId: 'cnt-01',
            contentType: 'article',
            authorId: 'user-author',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('article', $event->contentType);
        self::assertSame('user-author', $event->authorId);
    }

    #[Test]
    public function contentDeletedEvent(): void
    {
        $event = new ContentDeleted(
            contentId: 'cnt-01',
            deletedBy: 'user-admin',
            reason: 'Duplicate content',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('user-admin', $event->deletedBy);
        self::assertSame('Duplicate content', $event->reason);
    }

    #[Test]
    public function contentPublishedEvent(): void
    {
        $event = new ContentPublished(
            contentId: 'cnt-01',
            publishedBy: 'user-editor',
            reason: 'Approved by editorial team',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('user-editor', $event->publishedBy);
        self::assertSame('Approved by editorial team', $event->reason);
    }

    #[Test]
    public function contentRestoredEvent(): void
    {
        $event = new ContentRestored(
            contentId: 'cnt-01',
            restoredBy: 'user-admin',
            reason: 'Content still relevant',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('user-admin', $event->restoredBy);
        self::assertSame('Content still relevant', $event->reason);
    }

    #[Test]
    public function contentUpdatedEvent(): void
    {
        $event = new ContentUpdated(
            contentId: 'cnt-01',
            locale: 'en',
            changedFields: ['title', 'body', 'metaDescription'],
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('en', $event->locale);
        self::assertCount(3, $event->changedFields);
        self::assertContains('title', $event->changedFields);
    }

    #[Test]
    public function reviewRequestedEvent(): void
    {
        $event = new ReviewRequested(
            contentId: 'cnt-01',
            requesterId: 'user-author',
            reviewerId: 'user-editor',
            contentTitle: 'PHP Security Guide',
            message: 'Ready for review',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('user-author', $event->requesterId);
        self::assertSame('user-editor', $event->reviewerId);
        self::assertSame('PHP Security Guide', $event->contentTitle);
        self::assertSame('Ready for review', $event->message);
    }

    #[Test]
    public function reviewRequestedEventWithoutReviewer(): void
    {
        $event = new ReviewRequested(
            contentId: 'cnt-02',
            requesterId: 'user-author',
            reviewerId: null,
            contentTitle: 'New Article',
            message: null,
        );

        self::assertNull($event->reviewerId);
        self::assertNull($event->message);
    }

    #[Test]
    public function revisionCreatedEvent(): void
    {
        $event = new RevisionCreated(
            revisionId: 'rev-01',
            contentId: 'cnt-01',
            locale: 'en',
        );

        self::assertSame('rev-01', $event->revisionId);
        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('en', $event->locale);
    }

    #[Test]
    public function slugChangedEvent(): void
    {
        $event = new SlugChanged(
            contentId: 'cnt-01',
            locale: 'en',
            oldSlug: 'old-article-title',
            newSlug: 'new-article-title',
        );

        self::assertSame('cnt-01', $event->contentId);
        self::assertSame('en', $event->locale);
        self::assertSame('old-article-title', $event->oldSlug);
        self::assertSame('new-article-title', $event->newSlug);
    }
}
