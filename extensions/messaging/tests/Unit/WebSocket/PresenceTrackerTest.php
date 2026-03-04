<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Internal\Service\InMemoryPresenceService;
use Pulsar\Extension\Messaging\WebSocket\PresenceTracker;
use Pulsar\WebSocket\BroadcastManagerInterface;

#[CoversClass(PresenceTracker::class)]
final class PresenceTrackerTest extends TestCase
{
    public function testOnConnectMarksUserOnlineAndBroadcasts(): void
    {
        $presenceService = new InMemoryPresenceService();
        $broadcast = $this->createStub(BroadcastManagerInterface::class);

        $tracker = new PresenceTracker($presenceService, $broadcast);
        $tracker->onConnect('user-1');

        self::assertTrue($tracker->isOnline('user-1'));
        self::assertContains('user-1', $tracker->getOnlineUsers());
    }

    public function testOnDisconnectMarksUserOffline(): void
    {
        $presenceService = new InMemoryPresenceService();
        $broadcast = $this->createStub(BroadcastManagerInterface::class);

        $tracker = new PresenceTracker($presenceService, $broadcast);
        $tracker->onConnect('user-1');
        self::assertTrue($tracker->isOnline('user-1'));

        $tracker->onDisconnect('user-1');
        self::assertFalse($tracker->isOnline('user-1'));
    }

    public function testMultipleUsersOnline(): void
    {
        $presenceService = new InMemoryPresenceService();
        $broadcast = $this->createStub(BroadcastManagerInterface::class);

        $tracker = new PresenceTracker($presenceService, $broadcast);
        $tracker->onConnect('user-1');
        $tracker->onConnect('user-2');
        $tracker->onConnect('user-3');

        $online = $tracker->getOnlineUsers();
        self::assertCount(3, $online);
        self::assertContains('user-1', $online);
        self::assertContains('user-2', $online);
        self::assertContains('user-3', $online);
    }

    public function testIsOnlineReturnsFalseForUnknownUser(): void
    {
        $presenceService = new InMemoryPresenceService();
        $broadcast = $this->createStub(BroadcastManagerInterface::class);

        $tracker = new PresenceTracker($presenceService, $broadcast);

        self::assertFalse($tracker->isOnline('unknown'));
    }
}
