<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\ThreadReplyNotification;

#[CoversClass(ThreadReplyNotification::class)]
final class ThreadReplyNotificationTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $n = new ThreadReplyNotification(threadId: 'thread-001', threadTitle: 'Test', postId: 'post-001', authorId: 'user-001', authorName: 'Alice', recipientUserIds: ['user-002']);
        self::assertInstanceOf(ForumNotificationInterface::class, $n);
    }

    #[Test]
    public function typeReturnsThreadReply(): void
    {
        $n = new ThreadReplyNotification(threadId: 'thread-001', threadTitle: 'Test', postId: 'post-001', authorId: 'user-001', authorName: 'Alice', recipientUserIds: ['user-002']);
        self::assertSame('thread_reply', $n->type());
    }

    #[Test]
    public function recipientIdsReturnsSubscribers(): void
    {
        $n = new ThreadReplyNotification(threadId: 'thread-001', threadTitle: 'Test', postId: 'post-001', authorId: 'user-001', authorName: 'Alice', recipientUserIds: ['user-002', 'user-003']);
        self::assertSame(['user-002', 'user-003'], $n->recipientIds());
    }

    #[Test]
    public function subjectContainsThreadTitle(): void
    {
        $n = new ThreadReplyNotification(threadId: 'thread-001', threadTitle: 'PHP 8.5', postId: 'post-001', authorId: 'user-001', authorName: 'Alice', recipientUserIds: ['user-002']);
        self::assertSame('New reply in: PHP 8.5', $n->subject());
    }

    #[Test]
    public function bodyContainsAuthorAndTitle(): void
    {
        $n = new ThreadReplyNotification(threadId: 'thread-001', threadTitle: 'PHP 8.5', postId: 'post-001', authorId: 'user-001', authorName: 'Alice', recipientUserIds: ['user-002']);
        self::assertStringContainsString('Alice', $n->body());
        self::assertStringContainsString('PHP 8.5', $n->body());
    }

    #[Test]
    public function metadataContainsExpectedKeys(): void
    {
        $n = new ThreadReplyNotification(threadId: 'thread-001', threadTitle: 'Test', postId: 'post-001', authorId: 'user-001', authorName: 'Alice', recipientUserIds: ['user-002']);
        $meta = $n->metadata();
        self::assertSame('thread-001', $meta['thread_id']);
        self::assertSame('post-001', $meta['post_id']);
        self::assertSame('user-001', $meta['author_id']);
    }
}
