<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\SolutionAcceptedNotification;

#[CoversClass(SolutionAcceptedNotification::class)]
final class SolutionAcceptedNotificationTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $n = new SolutionAcceptedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', acceptedBy: 'user-002');
        self::assertInstanceOf(ForumNotificationInterface::class, $n);
    }

    #[Test]
    public function typeReturnsSolutionAccepted(): void
    {
        $n = new SolutionAcceptedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', acceptedBy: 'user-002');
        self::assertSame('solution_accepted', $n->type());
    }

    #[Test]
    public function recipientIdsReturnsPostAuthor(): void
    {
        $n = new SolutionAcceptedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', acceptedBy: 'user-002');
        self::assertSame(['user-001'], $n->recipientIds());
    }

    #[Test]
    public function subjectContainsThreadTitle(): void
    {
        $n = new SolutionAcceptedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'How to fix X', postAuthorId: 'user-001', acceptedBy: 'user-002');
        self::assertStringContainsString('How to fix X', $n->subject());
    }

    #[Test]
    public function bodyContainsThreadTitle(): void
    {
        $n = new SolutionAcceptedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'How to fix X', postAuthorId: 'user-001', acceptedBy: 'user-002');
        self::assertStringContainsString('How to fix X', $n->body());
    }

    #[Test]
    public function metadataContainsExpectedKeys(): void
    {
        $n = new SolutionAcceptedNotification(postId: 'post-001', threadId: 'thread-001', threadTitle: 'Test', postAuthorId: 'user-001', acceptedBy: 'user-002');
        $meta = $n->metadata();
        self::assertSame('post-001', $meta['post_id']);
        self::assertSame('thread-001', $meta['thread_id']);
        self::assertSame('user-002', $meta['accepted_by']);
    }
}
