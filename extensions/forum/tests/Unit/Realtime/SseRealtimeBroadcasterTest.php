<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Realtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Realtime\RealtimeEvent;
use Pulsar\Extension\Forum\Realtime\RealtimeEventType;
use Pulsar\Extension\Forum\Realtime\SseRealtimeBroadcaster;

final class SseRealtimeBroadcasterTest extends TestCase
{
    private SseRealtimeBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->broadcaster = new SseRealtimeBroadcaster();
    }

    #[Test]
    public function broadcastStoresEventInChannel(): void
    {
        $event = new RealtimeEvent(
            type: RealtimeEventType::NewPost,
            channelId: 'thread-123',
            payload: '{"post_id":"p-1"}',
            userId: 'user-1',
        );

        $this->broadcaster->broadcast($event);

        $recent = $this->broadcaster->getRecentEvents('thread-123');
        self::assertCount(1, $recent);
        self::assertSame(RealtimeEventType::NewPost, $recent[0]->type);
        self::assertSame('thread-123', $recent[0]->channelId);
    }

    #[Test]
    public function broadcastCapsBufferAt100Events(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->broadcaster->broadcast(new RealtimeEvent(
                type: RealtimeEventType::NewPost,
                channelId: 'thread-456',
                payload: "event-{$i}",
            ));
        }

        $recent = $this->broadcaster->getRecentEvents('thread-456');
        self::assertCount(100, $recent);
        // First 20 events should have been trimmed
        self::assertSame('event-20', $recent[0]->payload);
    }

    #[Test]
    public function getRecentEventsReturnsEmptyForUnknownChannel(): void
    {
        $recent = $this->broadcaster->getRecentEvents('nonexistent');

        self::assertSame([], $recent);
    }

    #[Test]
    public function getRecentEventsSinceIndexReturnsSubset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->broadcaster->broadcast(new RealtimeEvent(
                type: RealtimeEventType::NewPost,
                channelId: 'ch-1',
                payload: "msg-{$i}",
            ));
        }

        $since3 = $this->broadcaster->getRecentEvents('ch-1', sinceIndex: 3);
        self::assertCount(2, $since3);
        self::assertSame('msg-3', $since3[0]->payload);
    }

    #[Test]
    public function trackPresenceAddsUserToChannel(): void
    {
        $this->broadcaster->trackPresence('thread-1', 'user-a');
        $this->broadcaster->trackPresence('thread-1', 'user-b');

        $presence = $this->broadcaster->getPresence('thread-1');
        self::assertContains('user-a', $presence);
        self::assertContains('user-b', $presence);
        self::assertCount(2, $presence);
    }

    #[Test]
    public function trackPresenceDoesNotDuplicateUser(): void
    {
        $this->broadcaster->trackPresence('thread-1', 'user-a');
        $this->broadcaster->trackPresence('thread-1', 'user-a');

        $presence = $this->broadcaster->getPresence('thread-1');
        self::assertCount(1, $presence);
    }

    #[Test]
    public function removePresenceRemovesUserFromChannel(): void
    {
        $this->broadcaster->trackPresence('thread-1', 'user-a');
        $this->broadcaster->trackPresence('thread-1', 'user-b');

        $this->broadcaster->removePresence('thread-1', 'user-a');

        $presence = $this->broadcaster->getPresence('thread-1');
        self::assertNotContains('user-a', $presence);
        self::assertContains('user-b', $presence);
    }

    #[Test]
    public function removePresenceHandlesNonexistentChannel(): void
    {
        // Should not throw
        $this->broadcaster->removePresence('nonexistent', 'user-a');
        self::assertSame([], $this->broadcaster->getPresence('nonexistent'));
    }

    #[Test]
    public function getPresenceReturnsEmptyForUnknownChannel(): void
    {
        $presence = $this->broadcaster->getPresence('nonexistent');
        self::assertSame([], $presence);
    }
}
