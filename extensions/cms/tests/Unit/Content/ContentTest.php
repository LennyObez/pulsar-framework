<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;

final class ContentTest extends TestCase
{
    #[Test]
    public function create_returns_draft_content(): void
    {
        $content = Content::create(
            id: 'content-1',
            contentType: ContentType::Article,
            authorId: 'author-1',
        );

        self::assertSame('content-1', $content->id);
        self::assertSame(ContentType::Article, $content->contentType);
        self::assertSame('author-1', $content->authorId);
        self::assertSame(PublishingStatus::Draft, $content->status);
        self::assertNull($content->publishedAt);
        self::assertNull($content->deletedAt);
        self::assertTrue($content->isDraft());
        self::assertFalse($content->isPublished());
        self::assertFalse($content->isDeleted());
    }

    #[Test]
    public function create_with_optional_params(): void
    {
        $content = Content::create(
            id: 'content-1',
            contentType: ContentType::Page,
            authorId: 'author-1',
            tenantId: 'tenant-1',
            template: 'custom-template',
            parentId: 'parent-1',
            sortOrder: 5,
            commentPolicy: CommentPolicy::Closed,
            dataClassification: DataClassification::Confidential,
        );

        self::assertSame('tenant-1', $content->tenantId);
        self::assertSame('custom-template', $content->template);
        self::assertSame('parent-1', $content->parentId);
        self::assertSame(5, $content->sortOrder);
        self::assertSame(CommentPolicy::Closed, $content->commentPolicy);
        self::assertSame(DataClassification::Confidential, $content->dataClassification);
    }

    #[Test]
    public function publish_transitions_from_draft(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1');

        $published = $content->publish();

        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertTrue($published->isPublished());
        self::assertNotNull($published->publishedAt);
    }

    #[Test]
    public function publish_from_published_throws(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1')->publish();

        $this->expectException(CmsException::class);
        $content->publish();
    }

    #[Test]
    public function archive_from_published(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1')->publish();

        $archived = $content->archive();

        self::assertSame(PublishingStatus::Archived, $archived->status);
    }

    #[Test]
    public function archive_from_draft_throws(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1');

        $this->expectException(CmsException::class);
        $content->archive();
    }

    #[Test]
    public function restore_from_archived(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1')
            ->publish()
            ->archive();

        $restored = $content->restore();

        self::assertSame(PublishingStatus::Draft, $restored->status);
    }

    #[Test]
    public function schedule_from_draft(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1');
        $publishAt = new DateTimeImmutable('+1 day');

        $scheduled = $content->schedule($publishAt);

        self::assertSame(PublishingStatus::Scheduled, $scheduled->status);
        self::assertSame($publishAt, $scheduled->scheduledPublishAt);
    }

    #[Test]
    public function set_parent_returns_new_instance(): void
    {
        $content = Content::create('c1', ContentType::Page, 'a1');

        $withParent = $content->setParent('parent-1');

        self::assertSame('parent-1', $withParent->parentId);
        self::assertNull($content->parentId);
    }

    #[Test]
    public function editorial_workflow_submit_for_review(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1');

        $inReview = $content->submitForReview();

        self::assertSame(PublishingStatus::InReview, $inReview->status);
    }

    #[Test]
    public function editorial_workflow_approve(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1')
            ->submitForReview();

        $approved = $content->approve();

        self::assertSame(PublishingStatus::Approved, $approved->status);
    }

    #[Test]
    public function editorial_workflow_reject_returns_to_draft(): void
    {
        $content = Content::create('c1', ContentType::Article, 'a1')
            ->submitForReview();

        $rejected = $content->reject();

        self::assertSame(PublishingStatus::Draft, $rejected->status);
    }
}
