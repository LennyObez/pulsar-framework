<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Publishing\PublishingOrchestratorInterface;

#[CoversClass(PublishingStateMachine::class)]
final class PublishingStateMachineTest extends TestCase
{
    #[Test]
    public function transition_from_draft_to_published(): void
    {
        $sm = new PublishingStateMachine();
        $content = Content::create('c1', ContentType::Article, 'a1');

        $result = $sm->transition($content, PublishingStatus::Published);

        self::assertSame(PublishingStatus::Published, $result->status);
        self::assertNotNull($result->publishedAt);
    }

    #[Test]
    public function transition_preserves_first_publishedAt(): void
    {
        $sm = new PublishingStateMachine();
        $content = Content::create('c1', ContentType::Article, 'a1');

        $published = $sm->transition($content, PublishingStatus::Published);
        $originalPublishedAt = $published->publishedAt;

        $archived = $sm->transition($published, PublishingStatus::Archived);
        $draft = $sm->transition($archived, PublishingStatus::Draft);
        $republished = $sm->transition($draft, PublishingStatus::Published);

        self::assertSame($originalPublishedAt, $republished->publishedAt);
    }

    #[Test]
    public function transition_throws_for_invalid_transition(): void
    {
        $sm = new PublishingStateMachine();
        $content = Content::create('c1', ContentType::Article, 'a1');

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains("Invalid status transition from 'draft' to 'archived'");

        $sm->transition($content, PublishingStatus::Archived);
    }

    #[Test]
    public function transition_to_published_triggers_orchestrator(): void
    {
        $orchestrator = $this->createMock(PublishingOrchestratorInterface::class);
        $orchestrator->expects(self::once())->method('publishToAll');

        $sm = new PublishingStateMachine($orchestrator);
        $content = Content::create('c1', ContentType::Article, 'a1');

        $sm->transition($content, PublishingStatus::Published);
    }

    #[Test]
    public function transition_to_archived_from_published_triggers_unpublish(): void
    {
        $orchestrator = $this->createMock(PublishingOrchestratorInterface::class);
        $orchestrator->expects(self::once())->method('unpublishFromAll');

        $sm = new PublishingStateMachine($orchestrator);
        $content = Content::create('c1', ContentType::Article, 'a1');
        $published = $sm->transition($content, PublishingStatus::Published);

        $sm->transition($published, PublishingStatus::Archived);
    }

    #[Test]
    public function transition_without_orchestrator_does_not_error(): void
    {
        $sm = new PublishingStateMachine();
        $content = Content::create('c1', ContentType::Article, 'a1');

        $result = $sm->transition($content, PublishingStatus::Published);

        self::assertSame(PublishingStatus::Published, $result->status);
    }

    #[Test]
    public function transition_to_draft_clears_scheduled_dates(): void
    {
        $sm = new PublishingStateMachine();
        $content = Content::create('c1', ContentType::Article, 'a1');
        $published = $sm->transition($content, PublishingStatus::Published);
        $archived = $sm->transition($published, PublishingStatus::Archived);
        $draft = $sm->transition($archived, PublishingStatus::Draft);

        self::assertNull($draft->scheduledPublishAt);
        self::assertNull($draft->scheduledUnpublishAt);
    }
}
