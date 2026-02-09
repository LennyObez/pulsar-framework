<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Cms\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Notification\ReviewRequestNotification;

#[CoversClass(ReviewRequestNotification::class)]
final class ReviewRequestNotificationTest extends TestCase
{
    #[Test]
    public function type_returns_review_requested(): void
    {
        $notification = $this->createNotification();

        self::assertSame('review_requested', $notification->type());
    }

    #[Test]
    public function subject_contains_content_title(): void
    {
        $notification = $this->createNotification();

        self::assertSame('Review requested: Draft Post', $notification->subject());
    }

    #[Test]
    public function recipient_ids_returns_reviewer_ids(): void
    {
        $notification = $this->createNotification();

        self::assertSame(['reviewer-1', 'reviewer-2'], $notification->recipientIds());
    }

    #[Test]
    public function body_contains_title_and_message(): void
    {
        $notification = new ReviewRequestNotification(
            contentId: 'c-1',
            contentTitle: 'Draft Post',
            requesterId: 'user-1',
            reviewerIds: ['reviewer-1'],
            message: 'Please review before Friday',
        );

        self::assertStringContainsString('Draft Post', $notification->body());
        self::assertStringContainsString('Please review before Friday', $notification->body());
    }

    #[Test]
    public function body_without_message_omits_message_section(): void
    {
        $notification = new ReviewRequestNotification(
            contentId: 'c-1',
            contentTitle: 'Draft Post',
            requesterId: 'user-1',
            reviewerIds: ['reviewer-1'],
            message: null,
        );

        self::assertStringContainsString('Draft Post', $notification->body());
        self::assertStringNotContainsString('Message:', $notification->body());
    }

    #[Test]
    public function metadata_contains_structured_data(): void
    {
        $notification = $this->createNotification();
        $metadata = $notification->metadata();

        self::assertSame('c-1', $metadata['content_id']);
        self::assertSame('user-1', $metadata['requester_id']);
        self::assertSame(['reviewer-1', 'reviewer-2'], $metadata['reviewer_ids']);
    }

    private function createNotification(): ReviewRequestNotification
    {
        return new ReviewRequestNotification(
            contentId: 'c-1',
            contentTitle: 'Draft Post',
            requesterId: 'user-1',
            reviewerIds: ['reviewer-1', 'reviewer-2'],
            message: 'Please review ASAP',
        );
    }
}
