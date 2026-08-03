<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(Content::class)]
#[CoversClass(PublishingStatus::class)]
final class PublishingWorkflowTest extends TestCase
{
    #[Test]
    public function standardWorkflowDraftToPublished(): void
    {
        $content = Content::create(
            id: '019461b0-0000-7000-8000-000000000001',
            contentType: ContentType::Article,
            authorId: 'author-001',
        );

        self::assertSame(PublishingStatus::Draft, $content->status);

        $published = $content->publish();

        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertNotNull($published->publishedAt);
        self::assertTrue($published->isPublished());
        self::assertFalse($published->isDraft());
    }

    #[Test]
    public function editorialWorkflowFullPipeline(): void
    {
        $content = Content::create(
            id: '019461b0-0000-7000-8000-000000000002',
            contentType: ContentType::Article,
            authorId: 'author-002',
        );

        // Draft -> InReview
        $inReview = $content->submitForReview();
        self::assertSame(PublishingStatus::InReview, $inReview->status);

        // InReview -> Approved
        $approved = $inReview->approve();
        self::assertSame(PublishingStatus::Approved, $approved->status);

        // Approved -> Published (editorial workflow)
        $published = $approved->publish(editorialWorkflow: true);
        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertNotNull($published->publishedAt);
    }

    #[Test]
    public function invalidTransitionThrowsException(): void
    {
        $content = Content::create(
            id: '019461b0-0000-7000-8000-000000000003',
            contentType: ContentType::Article,
            authorId: 'author-003',
        );

        $published = $content->publish();

        // Published -> Draft is not a valid transition
        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains("Invalid status transition from 'published' to 'draft'");

        $published->restore();
    }

    #[Test]
    public function scheduleContentWithFutureDate(): void
    {
        $content = Content::create(
            id: '019461b0-0000-7000-8000-000000000004',
            contentType: ContentType::Article,
            authorId: 'author-004',
        );

        $futureDate = new DateTimeImmutable('+7 days');
        $scheduled = $content->schedule($futureDate);

        self::assertSame(PublishingStatus::Scheduled, $scheduled->status);
        self::assertNotNull($scheduled->scheduledPublishAt);
        self::assertEquals($futureDate, $scheduled->scheduledPublishAt);
    }

    #[Test]
    public function archivePublishedContent(): void
    {
        $content = Content::create(
            id: '019461b0-0000-7000-8000-000000000005',
            contentType: ContentType::Article,
            authorId: 'author-005',
        );

        $published = $content->publish();
        $archived = $published->archive();

        self::assertSame(PublishingStatus::Archived, $archived->status);
        self::assertFalse($archived->isPublished());
    }

    #[Test]
    public function restoreArchivedContentToDraft(): void
    {
        $content = Content::create(
            id: '019461b0-0000-7000-8000-000000000006',
            contentType: ContentType::Article,
            authorId: 'author-006',
        );

        $published = $content->publish();
        $archived = $published->archive();
        $restored = $archived->restore();

        self::assertSame(PublishingStatus::Draft, $restored->status);
        self::assertTrue($restored->isDraft());
        // publishedAt is preserved from original publication
        self::assertNotNull($restored->publishedAt);
    }
}
