<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;

#[CoversClass(PublishingStateMachine::class)]
final class PublishingStateMachineTest extends TestCase
{
    #[Test]
    public function transitionDraftToPublished(): void
    {
        $machine = new PublishingStateMachine();
        $content = Content::create(id: 'c-01', contentType: ContentType::Article, authorId: 'u-01');

        $result = $machine->transition($content, PublishingStatus::Published, 'u-01');

        self::assertSame(PublishingStatus::Published, $result->status);
        self::assertNotNull($result->publishedAt);
        self::assertNull($result->scheduledPublishAt);
    }

    #[Test]
    public function transitionDraftToScheduled(): void
    {
        $machine = new PublishingStateMachine();
        $content = Content::create(id: 'c-02', contentType: ContentType::Page, authorId: 'u-01');

        $result = $machine->transition($content, PublishingStatus::Scheduled, 'u-01');

        self::assertSame(PublishingStatus::Scheduled, $result->status);
    }

    #[Test]
    public function transitionScheduledToPublished(): void
    {
        $machine = new PublishingStateMachine();
        $draft = Content::create(id: 'c-03', contentType: ContentType::Article, authorId: 'u-01');
        $scheduled = $machine->transition($draft, PublishingStatus::Scheduled, 'u-01');

        $published = $machine->transition($scheduled, PublishingStatus::Published, 'u-01');

        self::assertSame(PublishingStatus::Published, $published->status);
        self::assertNotNull($published->publishedAt);
    }

    #[Test]
    public function transitionPublishedToArchived(): void
    {
        $machine = new PublishingStateMachine();
        $draft = Content::create(id: 'c-04', contentType: ContentType::Article, authorId: 'u-01');
        $published = $machine->transition($draft, PublishingStatus::Published, 'u-01');

        $archived = $machine->transition($published, PublishingStatus::Archived, 'u-01');

        self::assertSame(PublishingStatus::Archived, $archived->status);
    }

    #[Test]
    public function transitionArchivedToDraft(): void
    {
        $machine = new PublishingStateMachine();
        $draft = Content::create(id: 'c-05', contentType: ContentType::Article, authorId: 'u-01');
        $published = $machine->transition($draft, PublishingStatus::Published, 'u-01');
        $archived = $machine->transition($published, PublishingStatus::Archived, 'u-01');

        $restored = $machine->transition($archived, PublishingStatus::Draft, 'u-01');

        self::assertSame(PublishingStatus::Draft, $restored->status);
        self::assertNull($restored->scheduledPublishAt);
        self::assertNull($restored->scheduledUnpublishAt);
    }

    #[Test]
    public function transitionInvalidThrowsCmsException(): void
    {
        $machine = new PublishingStateMachine();
        $content = Content::create(id: 'c-06', contentType: ContentType::Article, authorId: 'u-01');

        $this->expectException(CmsException::class);

        $machine->transition($content, PublishingStatus::Archived, 'u-01');
    }

    #[Test]
    public function transitionPublishSetsPublishedAtFirstTimeOnly(): void
    {
        $machine = new PublishingStateMachine();
        $content = Content::create(id: 'c-07', contentType: ContentType::Article, authorId: 'u-01');

        $published = $machine->transition($content, PublishingStatus::Published, 'u-01');
        $firstPublishedAt = $published->publishedAt;

        self::assertNotNull($firstPublishedAt);

        $archived = $machine->transition($published, PublishingStatus::Archived, 'u-01');
        $restored = $machine->transition($archived, PublishingStatus::Draft, 'u-01');
        $republished = $machine->transition($restored, PublishingStatus::Published, 'u-01');

        self::assertSame($firstPublishedAt, $republished->publishedAt);
    }

    #[Test]
    public function transitionClearsScheduledFieldsOnDraft(): void
    {
        $machine = new PublishingStateMachine();
        $content = Content::create(id: 'c-08', contentType: ContentType::Article, authorId: 'u-01');

        $scheduled = $machine->transition($content, PublishingStatus::Scheduled, 'u-01');
        $published = $machine->transition($scheduled, PublishingStatus::Published, 'u-01');

        // Published clears scheduledPublishAt
        self::assertNull($published->scheduledPublishAt);
    }

    #[Test]
    public function transitionToDraftClearsAllScheduledDates(): void
    {
        $machine = new PublishingStateMachine();
        $draft = Content::create(id: 'c-09', contentType: ContentType::Article, authorId: 'u-01');
        $published = $machine->transition($draft, PublishingStatus::Published, 'u-01');
        $archived = $machine->transition($published, PublishingStatus::Archived, 'u-01');

        $restored = $machine->transition($archived, PublishingStatus::Draft, 'u-01');

        self::assertNull($restored->scheduledPublishAt);
        self::assertNull($restored->scheduledUnpublishAt);
    }

    #[Test]
    public function transitionPreservesPublishedAtOnRepublish(): void
    {
        $machine = new PublishingStateMachine();
        $draft = Content::create(id: 'c-10', contentType: ContentType::Article, authorId: 'u-01');

        $firstPublish = $machine->transition($draft, PublishingStatus::Published, 'u-01');
        $firstPublishedAt = $firstPublish->publishedAt;

        $archived = $machine->transition($firstPublish, PublishingStatus::Archived, 'u-01');
        $restored = $machine->transition($archived, PublishingStatus::Draft, 'u-01');
        $republished = $machine->transition($restored, PublishingStatus::Published, 'u-01');

        self::assertSame($firstPublishedAt, $republished->publishedAt);
    }

    #[Test]
    public function transitionEditorialWorkflowDraftToInReview(): void
    {
        $machine = new PublishingStateMachine();
        $content = Content::create(id: 'c-11', contentType: ContentType::Article, authorId: 'u-01');

        $result = $machine->transition($content, PublishingStatus::InReview, 'u-01', editorialWorkflow: true);

        self::assertSame(PublishingStatus::InReview, $result->status);
    }

    #[Test]
    public function transitionWithoutOrchestratorDoesNotFail(): void
    {
        $machine = new PublishingStateMachine(null);
        $content = Content::create(id: 'c-12', contentType: ContentType::Article, authorId: 'u-01');

        $result = $machine->transition($content, PublishingStatus::Published, 'u-01');

        self::assertSame(PublishingStatus::Published, $result->status);
    }
}
