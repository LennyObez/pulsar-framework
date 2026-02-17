<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\CommentPolicy;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Content\PublishingStatus;

#[CoversClass(Content::class)]
final class ContentWithStatusTest extends TestCase
{
    #[Test]
    public function withStatusReturnsSameInstanceWhenUnchanged(): void
    {
        $content = $this->createDraftContent();

        $result = $content->withStatus(PublishingStatus::Draft);

        self::assertSame($content, $result);
    }

    #[Test]
    public function withStatusTransitionsToPublished(): void
    {
        $content = $this->createDraftContent();

        $result = $content->withStatus(PublishingStatus::Published);

        self::assertSame(PublishingStatus::Published, $result->status);
        self::assertNotNull($result->publishedAt);
    }

    #[Test]
    public function withStatusPreservesExistingPublishedAt(): void
    {
        $publishedAt = new DateTimeImmutable('2026-01-15T10:00:00Z');
        $content = new Content(
            id: 'test-id',
            tenantId: null,
            contentType: ContentType::Page,
            authorId: 'author-1',
            status: PublishingStatus::Published,
            scheduledPublishAt: null,
            scheduledUnpublishAt: null,
            publishedAt: $publishedAt,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            deletedAt: null,
            template: null,
            parentId: null,
            sortOrder: 0,
            commentPolicy: CommentPolicy::Closed,
            dataClassification: DataClassification::Public,
        );

        $result = $content->withStatus(PublishingStatus::Archived);

        self::assertSame(PublishingStatus::Archived, $result->status);
        self::assertSame($publishedAt, $result->publishedAt);
    }

    #[Test]
    public function withStatusSetsPublishedAtOnlyWhenNull(): void
    {
        $content = $this->createDraftContent();
        self::assertNull($content->publishedAt);

        $result = $content->withStatus(PublishingStatus::Published);

        self::assertNotNull($result->publishedAt);
    }

    #[Test]
    public function withStatusUpdatesTimestamp(): void
    {
        $content = $this->createDraftContent();
        $originalUpdatedAt = $content->updatedAt;

        $result = $content->withStatus(PublishingStatus::Published);

        self::assertGreaterThanOrEqual($originalUpdatedAt, $result->updatedAt);
    }

    #[Test]
    public function withStatusPreservesAllOtherProperties(): void
    {
        $content = $this->createDraftContent();

        $result = $content->withStatus(PublishingStatus::Published);

        self::assertSame($content->id, $result->id);
        self::assertSame($content->tenantId, $result->tenantId);
        self::assertSame($content->contentType, $result->contentType);
        self::assertSame($content->authorId, $result->authorId);
        self::assertSame($content->template, $result->template);
        self::assertSame($content->parentId, $result->parentId);
        self::assertSame($content->sortOrder, $result->sortOrder);
        self::assertSame($content->commentPolicy, $result->commentPolicy);
        self::assertSame($content->dataClassification, $result->dataClassification);
    }

    private function createDraftContent(): Content
    {
        return Content::create(
            id: 'test-id',
            contentType: ContentType::Page,
            authorId: 'author-1',
        );
    }
}
