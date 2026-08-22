<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Report;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ModerationAction;
use Pulsar\Extension\Forum\Report\ForumModerationLog;

#[CoversClass(ForumModerationLog::class)]
final class ForumModerationLogTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2025-01-15 12:00:00');

        $log = new ForumModerationLog(
            id: 'log-1',
            moderatorId: 'mod-1',
            action: ModerationAction::Hide,
            targetType: 'thread',
            targetId: 'thread-1',
            reason: 'Off topic',
            createdAt: $now,
        );

        self::assertSame('log-1', $log->id);
        self::assertSame('mod-1', $log->moderatorId);
        self::assertSame(ModerationAction::Hide, $log->action);
        self::assertSame('thread', $log->targetType);
        self::assertSame('thread-1', $log->targetId);
        self::assertSame('Off topic', $log->reason);
        self::assertSame($now, $log->createdAt);
    }

    #[Test]
    public function createFactoryGeneratesUuidAndTimestamp(): void
    {
        $log = ForumModerationLog::create(
            moderatorId: 'mod-1',
            action: ModerationAction::Delete,
            targetType: 'post',
            targetId: 'post-42',
            reason: 'Spam content',
        );

        self::assertNotEmpty($log->id);
        self::assertSame('mod-1', $log->moderatorId);
        self::assertSame(ModerationAction::Delete, $log->action);
        self::assertSame('post', $log->targetType);
        self::assertSame('post-42', $log->targetId);
        self::assertSame('Spam content', $log->reason);
        self::assertInstanceOf(DateTimeImmutable::class, $log->createdAt);
    }

    #[Test]
    public function createWithDifferentTargetTypes(): void
    {
        $threadLog = ForumModerationLog::create('mod-1', ModerationAction::Approve, 'thread', 'thread-1', 'Important');
        $postLog = ForumModerationLog::create('mod-1', ModerationAction::Delete, 'post', 'post-1', 'Spam');
        $userLog = ForumModerationLog::create('mod-1', ModerationAction::Ban, 'user', 'user-1', 'Abuse');

        self::assertSame('thread', $threadLog->targetType);
        self::assertSame('post', $postLog->targetType);
        self::assertSame('user', $userLog->targetType);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $log1 = ForumModerationLog::create('mod-1', ModerationAction::Hide, 'thread', 'thread-1', 'Reason 1');
        $log2 = ForumModerationLog::create('mod-1', ModerationAction::Hide, 'thread', 'thread-2', 'Reason 2');

        self::assertNotSame($log1->id, $log2->id);
    }
}
