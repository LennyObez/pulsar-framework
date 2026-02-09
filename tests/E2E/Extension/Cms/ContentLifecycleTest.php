<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStatus;

/**
 * E2E: Full content lifecycle — Draft -> Schedule -> Publish -> Archive -> Restore.
 */
#[Group('e2e-cms')]
final class ContentLifecycleTest extends TestCase
{
    #[Test]
    public function test_full_content_lifecycle(): void
    {
        // Step 1: Create draft
        $content = Content::create(
            id: '019e2e02-0000-7000-8000-000000000001',
            contentType: ContentType::Article,
            authorId: 'author-lifecycle',
        );
        self::assertSame(PublishingStatus::Draft, $content->status);
        self::assertTrue($content->isDraft());

        // Step 2: Schedule for future publication
        $futureDate = new DateTimeImmutable('+3 days');
        $scheduled = $content->schedule($futureDate);
        self::assertSame(PublishingStatus::Scheduled, $scheduled->status);
        self::assertEquals($futureDate, $scheduled->scheduledPublishAt);

        // Step 3: Publish (as if the scheduled time arrived)
        $published = $scheduled->publish();
        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertNotNull($published->publishedAt);
        self::assertTrue($published->isPublished());

        // Step 4: Archive
        $archived = $published->archive();
        self::assertSame(PublishingStatus::Archived, $archived->status);
        self::assertFalse($archived->isPublished());

        // Step 5: Restore back to draft
        $restored = $archived->restore();
        self::assertSame(PublishingStatus::Draft, $restored->status);
        self::assertTrue($restored->isDraft());
        // publishedAt preserved from original publication
        self::assertNotNull($restored->publishedAt);

        // Step 6: Re-publish
        $republished = $restored->publish();
        self::assertSame(PublishingStatus::Published, $republished->status);
        // Original publishedAt is preserved
        self::assertEquals($published->publishedAt, $republished->publishedAt);
    }

    #[Test]
    public function test_editorial_workflow_full_lifecycle(): void
    {
        $content = Content::create(
            id: '019e2e02-0000-7000-8000-000000000002',
            contentType: ContentType::Article,
            authorId: 'author-editorial',
        );

        // Draft -> InReview -> Approved -> Published -> Archived -> Restored -> InReview -> Rejected -> Draft
        $inReview = $content->submitForReview();
        self::assertSame(PublishingStatus::InReview, $inReview->status);

        $approved = $inReview->approve();
        self::assertSame(PublishingStatus::Approved, $approved->status);

        $published = $approved->publish(editorialWorkflow: true);
        self::assertSame(PublishingStatus::Published, $published->status);

        $archived = $published->archive();
        self::assertSame(PublishingStatus::Archived, $archived->status);

        $restored = $archived->restore();
        self::assertSame(PublishingStatus::Draft, $restored->status);

        $resubmitted = $restored->submitForReview();
        $rejected = $resubmitted->reject();
        self::assertSame(PublishingStatus::Draft, $rejected->status);
    }
}
