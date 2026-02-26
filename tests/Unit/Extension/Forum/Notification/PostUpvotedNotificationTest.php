<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\PostUpvotedNotification;

#[CoversClass(PostUpvotedNotification::class)]
final class PostUpvotedNotificationTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $n = new PostUpvotedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', voterId: 'user-002');
        self::assertInstanceOf(ForumNotificationInterface::class, $n);
    }

    #[Test]
    public function typeReturnsPostUpvoted(): void
    {
        $n = new PostUpvotedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', voterId: 'user-002');
        self::assertSame('post_upvoted', $n->type());
    }

    #[Test]
    public function recipientIdsReturnsPostAuthor(): void
    {
        $n = new PostUpvotedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', voterId: 'user-002');
        self::assertSame(['user-001'], $n->recipientIds());
    }

    #[Test]
    public function subjectContainsThreadTitle(): void
    {
        $n = new PostUpvotedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Best Practices', postAuthorId: 'user-001', voterId: 'user-002');
        self::assertStringContainsString('Best Practices', $n->subject());
    }

    #[Test]
    public function bodyContainsThreadTitle(): void
    {
        $n = new PostUpvotedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Best Practices', postAuthorId: 'user-001', voterId: 'user-002');
        self::assertStringContainsString('Best Practices', $n->body());
    }

    #[Test]
    public function metadataContainsExpectedKeys(): void
    {
        $n = new PostUpvotedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', voterId: 'user-002');
        $meta = $n->metadata();
        self::assertSame('post-001', $meta['post_id']);
        self::assertSame('thread-001', $meta['thread_id']);
        self::assertSame('user-002', $meta['voter_id']);
    }
}
