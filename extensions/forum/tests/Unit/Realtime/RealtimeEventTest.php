<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Realtime;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Realtime\RealtimeEvent;
use Pulsar\Extension\Forum\Realtime\RealtimeEventType;

final class RealtimeEventTest extends TestCase
{
    #[Test]
    public function toSseFormatsCorrectly(): void
    {
        $event = new RealtimeEvent(
            type: RealtimeEventType::NewPost,
            channelId: 'thread-1',
            payload: '{"id":"p-1"}',
        );

        $sse = $event->toSse();

        self::assertSame("event: new_post\ndata: {\"id\":\"p-1\"}\n\n", $sse);
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $ts = new DateTimeImmutable('2026-03-20 10:00:00');

        $event = new RealtimeEvent(
            type: RealtimeEventType::TypingStarted,
            channelId: 'thread-1',
            payload: '{}',
            userId: 'user-1',
            timestamp: $ts,
        );

        $arr = $event->toArray();

        self::assertSame('typing_started', $arr['type']);
        self::assertSame('thread-1', $arr['channel']);
        self::assertSame('user-1', $arr['user_id']);
        self::assertStringContainsString('2026-03-20', $arr['timestamp']);
    }

    #[Test]
    public function allEventTypesHaveStringValues(): void
    {
        foreach (RealtimeEventType::cases() as $type) {
            self::assertNotEmpty($type->value, "Event type {$type->name} has empty value");
        }

        self::assertCount(12, RealtimeEventType::cases());
    }
}
