<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Notification\CommentReceivedNotification;

#[CoversClass(CommentReceivedNotification::class)]
final class CommentReceivedNotificationTest extends TestCase
{
    #[Test]
    public function type_returns_comment_received(): void
    {
        $notification = $this->createNotification();

        self::assertSame('comment_received', $notification->type());
    }

    #[Test]
    public function subject_contains_content_title(): void
    {
        $notification = $this->createNotification();

        self::assertSame('New comment on: My Article', $notification->subject());
    }

    #[Test]
    public function body_contains_author_and_content(): void
    {
        $notification = $this->createNotification();

        self::assertStringContainsString('Jane Doe', $notification->body());
        self::assertStringContainsString('My Article', $notification->body());
        self::assertStringContainsString('Great article!', $notification->body());
    }

    #[Test]
    public function recipient_ids_returns_configured_recipients(): void
    {
        $notification = new CommentReceivedNotification(
            contentId: 'c-1',
            contentTitle: 'My Article',
            commentId: 'comment-1',
            authorName: 'Jane Doe',
            commentBody: 'Great article!',
            recipientUserIds: ['author-1', 'moderator-1'],
        );

        self::assertSame(['author-1', 'moderator-1'], $notification->recipientIds());
    }

    #[Test]
    public function metadata_contains_structured_data(): void
    {
        $notification = $this->createNotification();
        $metadata = $notification->metadata();

        self::assertSame('c-1', $metadata['content_id']);
        self::assertSame('comment-1', $metadata['comment_id']);
        self::assertSame('Jane Doe', $metadata['author_name']);
    }

    private function createNotification(): CommentReceivedNotification
    {
        return new CommentReceivedNotification(
            contentId: 'c-1',
            contentTitle: 'My Article',
            commentId: 'comment-1',
            authorName: 'Jane Doe',
            commentBody: 'Great article!',
        );
    }
}
