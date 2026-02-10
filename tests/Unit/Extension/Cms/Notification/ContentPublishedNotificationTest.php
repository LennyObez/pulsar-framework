<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Notification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Notification\ContentPublishedNotification;

#[CoversClass(ContentPublishedNotification::class)]
final class ContentPublishedNotificationTest extends TestCase
{
    #[Test]
    public function type_returns_content_published(): void
    {
        $notification = $this->createNotification();

        self::assertSame('content_published', $notification->type());
    }

    #[Test]
    public function subject_contains_content_title(): void
    {
        $notification = $this->createNotification();

        self::assertSame('Content published: My Article', $notification->subject());
    }

    #[Test]
    public function body_contains_title_and_url(): void
    {
        $notification = $this->createNotification();

        self::assertStringContainsString('My Article', $notification->body());
        self::assertStringContainsString('/blog/my-article', $notification->body());
    }

    #[Test]
    public function recipient_ids_returns_configured_recipients(): void
    {
        $notification = new ContentPublishedNotification(
            contentId: 'c-1',
            contentTitle: 'My Article',
            authorId: 'user-1',
            publishedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            url: '/blog/my-article',
            recipientUserIds: ['user-1', 'user-2'],
        );

        self::assertSame(['user-1', 'user-2'], $notification->recipientIds());
    }

    #[Test]
    public function metadata_contains_structured_data(): void
    {
        $publishedAt = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $notification = new ContentPublishedNotification(
            contentId: 'c-1',
            contentTitle: 'My Article',
            authorId: 'user-1',
            publishedAt: $publishedAt,
            url: '/blog/my-article',
        );

        $metadata = $notification->metadata();

        self::assertSame('c-1', $metadata['content_id']);
        self::assertSame('user-1', $metadata['author_id']);
        self::assertSame($publishedAt->format('c'), $metadata['published_at']);
        self::assertSame('/blog/my-article', $metadata['url']);
    }

    private function createNotification(): ContentPublishedNotification
    {
        return new ContentPublishedNotification(
            contentId: 'c-1',
            contentTitle: 'My Article',
            authorId: 'user-1',
            publishedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            url: '/blog/my-article',
        );
    }
}
