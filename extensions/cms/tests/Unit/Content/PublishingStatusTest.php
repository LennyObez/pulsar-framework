<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\PublishingStatus;

final class PublishingStatusTest extends TestCase
{
    #[Test]
    public function draft_can_transition_to_published(): void
    {
        self::assertTrue(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Published));
    }

    #[Test]
    public function draft_can_transition_to_scheduled(): void
    {
        self::assertTrue(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Scheduled));
    }

    #[Test]
    public function draft_cannot_transition_to_archived(): void
    {
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Archived));
    }

    #[Test]
    public function draft_cannot_transition_to_in_review_without_editorial(): void
    {
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::InReview));
    }

    #[Test]
    public function draft_can_transition_to_in_review_with_editorial(): void
    {
        self::assertTrue(PublishingStatus::Draft->canTransitionTo(PublishingStatus::InReview, editorialWorkflow: true));
    }

    #[Test]
    public function same_status_transition_is_invalid(): void
    {
        self::assertFalse(PublishingStatus::Draft->canTransitionTo(PublishingStatus::Draft));
        self::assertFalse(PublishingStatus::Published->canTransitionTo(PublishingStatus::Published));
    }

    #[Test]
    public function published_can_transition_to_archived(): void
    {
        self::assertTrue(PublishingStatus::Published->canTransitionTo(PublishingStatus::Archived));
    }

    #[Test]
    public function archived_can_transition_to_draft(): void
    {
        self::assertTrue(PublishingStatus::Archived->canTransitionTo(PublishingStatus::Draft));
    }

    #[Test]
    public function scheduled_can_transition_to_published(): void
    {
        self::assertTrue(PublishingStatus::Scheduled->canTransitionTo(PublishingStatus::Published));
    }

    #[Test]
    public function is_publicly_visible_only_for_published(): void
    {
        self::assertTrue(PublishingStatus::Published->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Draft->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Archived->isPubliclyVisible());
        self::assertFalse(PublishingStatus::Scheduled->isPubliclyVisible());
        self::assertFalse(PublishingStatus::InReview->isPubliclyVisible());
    }

    #[Test]
    public function label_returns_human_readable(): void
    {
        self::assertSame('Draft', PublishingStatus::Draft->label());
        self::assertSame('Published', PublishingStatus::Published->label());
        self::assertSame('In Review', PublishingStatus::InReview->label());
        self::assertSame('Approved', PublishingStatus::Approved->label());
        self::assertSame('Scheduled', PublishingStatus::Scheduled->label());
        self::assertSame('Archived', PublishingStatus::Archived->label());
    }
}
