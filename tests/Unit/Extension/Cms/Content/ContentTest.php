<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use ReflectionClass;

#[CoversClass(Content::class)]
final class ContentTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const string AUTHOR_ID = '01912345-6789-7abc-8def-0123456789cd';

    // ── Construction / factory ────────────────────────────────────────

    #[Test]
    public function test_create_returns_draft_content(): void
    {
        $content = Content::create(
            id: self::CONTENT_ID,
            contentType: ContentType::Article,
            authorId: self::AUTHOR_ID,
        );

        self::assertSame(self::CONTENT_ID, $content->id);
        self::assertSame(ContentType::Article, $content->contentType);
        self::assertSame(self::AUTHOR_ID, $content->authorId);
        self::assertSame(PublishingStatus::Draft, $content->status);
        self::assertNull($content->tenantId);
        self::assertNull($content->scheduledPublishAt);
        self::assertNull($content->scheduledUnpublishAt);
        self::assertNull($content->publishedAt);
        self::assertNull($content->deletedAt);
        self::assertNull($content->template);
        self::assertNull($content->parentId);
        self::assertSame(0, $content->sortOrder);
        self::assertSame(CommentPolicy::Inherit, $content->commentPolicy);
        self::assertSame(DataClassification::Public, $content->dataClassification);
    }

    #[Test]
    public function test_create_with_all_optional_parameters(): void
    {
        $content = Content::create(
            id: self::CONTENT_ID,
            contentType: ContentType::Page,
            authorId: self::AUTHOR_ID,
            tenantId: '01912345-0000-7abc-8def-000000000001',
            template: 'landing-page',
            parentId: '01912345-0000-7abc-8def-000000000002',
            sortOrder: 5,
            commentPolicy: CommentPolicy::Closed,
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame(ContentType::Page, $content->contentType);
        self::assertSame('01912345-0000-7abc-8def-000000000001', $content->tenantId);
        self::assertSame('landing-page', $content->template);
        self::assertSame('01912345-0000-7abc-8def-000000000002', $content->parentId);
        self::assertSame(5, $content->sortOrder);
        self::assertSame(CommentPolicy::Closed, $content->commentPolicy);
        self::assertSame(DataClassification::Confidential, $content->dataClassification);
    }

    #[Test]
    public function test_create_sets_created_at_and_updated_at(): void
    {
        $before = new DateTimeImmutable();
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $content->createdAt);
        self::assertLessThanOrEqual($after, $content->createdAt);
        self::assertGreaterThanOrEqual($before, $content->updatedAt);
    }

    // ── Immutable state transitions ──────────────────────────────────

    #[Test]
    public function test_publish_returns_new_instance_with_published_status(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $published = $content->publish();

        self::assertNotSame($content, $published);
        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertSame(PublishingStatus::Draft, $content->status); // Original unchanged
        self::assertNotNull($published->publishedAt);
    }

    #[Test]
    public function test_publish_preserves_original_published_at(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $first = $content->publish();
        $archived = $first->archive();
        $restored = $archived->restore();
        // Re-publish (Draft -> Published)
        $republished = $restored->publish();

        self::assertSame($first->publishedAt, $republished->publishedAt);
    }

    #[Test]
    public function test_archive_returns_archived_content(): void
    {
        $published = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID)->publish();
        $archived = $published->archive();

        self::assertSame(PublishingStatus::Archived, $archived->status);
    }

    #[Test]
    public function test_restore_returns_draft_content(): void
    {
        $archived = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID)
            ->publish()
            ->archive();
        $restored = $archived->restore();

        self::assertSame(PublishingStatus::Draft, $restored->status);
    }

    #[Test]
    public function test_schedule_returns_scheduled_content(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $publishAt = new DateTimeImmutable('+1 day');
        $scheduled = $content->schedule($publishAt);

        self::assertSame(PublishingStatus::Scheduled, $scheduled->status);
        self::assertSame($publishAt, $scheduled->scheduledPublishAt);
    }

    #[Test]
    public function test_submit_for_review_transitions_to_in_review(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $inReview = $content->submitForReview();

        self::assertSame(PublishingStatus::InReview, $inReview->status);
    }

    #[Test]
    public function test_approve_transitions_to_approved(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $approved = $content->submitForReview()->approve();

        self::assertSame(PublishingStatus::Approved, $approved->status);
    }

    #[Test]
    public function test_reject_transitions_to_draft(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        $rejected = $content->submitForReview()->reject();

        self::assertSame(PublishingStatus::Draft, $rejected->status);
    }

    // ── Invalid transitions throw CmsException ──────────────────────

    #[Test]
    public function test_publish_from_archived_throws(): void
    {
        $archived = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID)
            ->publish()
            ->archive();

        $this->expectException(CmsException::class);
        $archived->publish();
    }

    #[Test]
    public function test_archive_from_draft_throws(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);

        $this->expectException(CmsException::class);
        $content->archive();
    }

    #[Test]
    public function test_restore_from_draft_throws(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);

        $this->expectException(CmsException::class);
        $content->restore();
    }

    // ── setParent ────────────────────────────────────────────────────

    #[Test]
    public function test_set_parent_returns_new_instance(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Page, self::AUTHOR_ID);
        $parentId = '01912345-0000-7abc-8def-000000000099';
        $withParent = $content->setParent($parentId);

        self::assertNotSame($content, $withParent);
        self::assertSame($parentId, $withParent->parentId);
        self::assertNull($content->parentId); // Original unchanged
    }

    #[Test]
    public function test_set_parent_null_clears_parent(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Page, self::AUTHOR_ID, parentId: 'some-parent');
        $orphaned = $content->setParent(null);

        self::assertNull($orphaned->parentId);
    }

    // ── Convenience methods ──────────────────────────────────────────

    #[Test]
    public function test_is_published(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        self::assertFalse($content->isPublished());

        $published = $content->publish();
        self::assertTrue($published->isPublished());
    }

    #[Test]
    public function test_is_draft(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        self::assertTrue($content->isDraft());

        $published = $content->publish();
        self::assertFalse($published->isDraft());
    }

    #[Test]
    public function test_is_deleted(): void
    {
        $now = new DateTimeImmutable();
        $content = new Content(
            id: self::CONTENT_ID,
            tenantId: null,
            contentType: ContentType::Article,
            authorId: self::AUTHOR_ID,
            status: PublishingStatus::Draft,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: null,
            createdAt: $now,
            updatedAt: $now,
            deletedAt: $now,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Inherit,
            dataClassification: DataClassification::Public,
            version: 1,
        );

        self::assertTrue($content->isDeleted());
    }

    #[Test]
    public function test_is_not_deleted_when_deleted_at_null(): void
    {
        $content = Content::create(self::CONTENT_ID, ContentType::Article, self::AUTHOR_ID);
        self::assertFalse($content->isDeleted());
    }

    // ── Immutability (readonly class) ────────────────────────────────

    #[Test]
    public function test_content_is_readonly(): void
    {
        $reflection = new ReflectionClass(Content::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
