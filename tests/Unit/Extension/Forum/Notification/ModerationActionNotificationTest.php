<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Notification\ForumNotificationInterface;
use Pulsar\Extension\Forum\Notification\ModerationActionNotification;

#[CoversClass(ModerationActionNotification::class)]
final class ModerationActionNotificationTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $n = new ModerationActionNotification(targetType: 'thread', targetId: 'thread-001', targetAuthorId: 'user-001', moderatorId: 'mod-001', action: 'locked', reason: 'Off-topic');
        self::assertInstanceOf(ForumNotificationInterface::class, $n);
    }

    #[Test]
    public function typeReturnsModerationAction(): void
    {
        $n = new ModerationActionNotification(targetType: 'thread', targetId: 'thread-001', targetAuthorId: 'user-001', moderatorId: 'mod-001', action: 'locked', reason: 'Off-topic');
        self::assertSame('moderation_action', $n->type());
    }

    #[Test]
    public function recipientIdsReturnsTargetAuthor(): void
    {
        $n = new ModerationActionNotification(targetType: 'post', targetId: 'post-001', targetAuthorId: 'user-001', moderatorId: 'mod-001', action: 'deleted', reason: 'Spam');
        self::assertSame(['user-001'], $n->recipientIds());
    }

    #[Test]
    public function subjectContainsTargetType(): void
    {
        $n = new ModerationActionNotification(targetType: 'thread', targetId: 'thread-001', targetAuthorId: 'user-001', moderatorId: 'mod-001', action: 'locked', reason: 'Off-topic');
        self::assertStringContainsString('thread', $n->subject());
    }

    #[Test]
    public function bodyContainsActionAndReason(): void
    {
        $n = new ModerationActionNotification(targetType: 'thread', targetId: 'thread-001', targetAuthorId: 'user-001', moderatorId: 'mod-001', action: 'locked', reason: 'Off-topic');
        self::assertStringContainsString('locked', $n->body());
        self::assertStringContainsString('Off-topic', $n->body());
    }

    #[Test]
    public function metadataContainsAllExpectedKeys(): void
    {
        $n = new ModerationActionNotification(targetType: 'thread', targetId: 'thread-001', targetAuthorId: 'user-001', moderatorId: 'mod-001', action: 'locked', reason: 'Off-topic');
        $meta = $n->metadata();
        self::assertSame('thread', $meta['target_type']);
        self::assertSame('thread-001', $meta['target_id']);
        self::assertSame('mod-001', $meta['moderator_id']);
        self::assertSame('locked', $meta['action']);
        self::assertSame('Off-topic', $meta['reason']);
    }
}
