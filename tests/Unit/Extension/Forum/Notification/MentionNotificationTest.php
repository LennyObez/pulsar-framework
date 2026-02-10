<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\MentionNotification;

#[CoversClass(MentionNotification::class)]
final class MentionNotificationTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $n = new MentionNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', mentionedUserId: 'user-002', authorId: 'user-001', authorName: 'Alice');
        self::assertInstanceOf(ForumNotificationInterface::class, $n);
    }

    #[Test]
    public function typeReturnsMention(): void
    {
        $n = new MentionNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', mentionedUserId: 'user-002', authorId: 'user-001', authorName: 'Alice');
        self::assertSame('mention', $n->type());
    }

    #[Test]
    public function recipientIdsReturnsMentionedUser(): void
    {
        $n = new MentionNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', mentionedUserId: 'user-002', authorId: 'user-001', authorName: 'Alice');
        self::assertSame(['user-002'], $n->recipientIds());
    }

    #[Test]
    public function subjectContainsAuthorAndTitle(): void
    {
        $n = new MentionNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'PHP Patterns', mentionedUserId: 'user-002', authorId: 'user-001', authorName: 'Bob');
        self::assertStringContainsString('Bob', $n->subject());
        self::assertStringContainsString('PHP Patterns', $n->subject());
    }

    #[Test]
    public function bodyContainsAuthorAndTitle(): void
    {
        $n = new MentionNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'PHP Patterns', mentionedUserId: 'user-002', authorId: 'user-001', authorName: 'Bob');
        self::assertStringContainsString('Bob', $n->body());
        self::assertStringContainsString('PHP Patterns', $n->body());
    }

    #[Test]
    public function metadataContainsExpectedKeys(): void
    {
        $n = new MentionNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', mentionedUserId: 'user-002', authorId: 'user-001', authorName: 'Alice');
        $meta = $n->metadata();
        self::assertSame('post-001', $meta['post_id']);
        self::assertSame('thread-001', $meta['thread_id']);
        self::assertSame('user-001', $meta['author_id']);
    }
}
