<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Internal\Service\InMemoryPresenceService;

#[CoversClass(InMemoryPresenceService::class)]
final class InMemoryPresenceServiceTest extends TestCase
{
    public function testSetOnlineAndIsOnline(): void
    {
        $service = new InMemoryPresenceService();
        $service->setOnline('user-1');

        self::assertTrue($service->isOnline('user-1'));
    }

    public function testIsOnlineReturnsFalseForUnknownUser(): void
    {
        $service = new InMemoryPresenceService();

        self::assertFalse($service->isOnline('user-1'));
    }

    public function testSetOffline(): void
    {
        $service = new InMemoryPresenceService();
        $service->setOnline('user-1');
        $service->setOffline('user-1');

        self::assertFalse($service->isOnline('user-1'));
    }

    public function testSetOfflineForNonExistentUserDoesNothing(): void
    {
        $service = new InMemoryPresenceService();
        $service->setOffline('nonexistent');

        self::assertFalse($service->isOnline('nonexistent'));
    }

    public function testGetOnlineUsers(): void
    {
        $service = new InMemoryPresenceService();
        $service->setOnline('user-1');
        $service->setOnline('user-2');
        $service->setOnline('user-3');

        $online = $service->getOnlineUsers();
        self::assertCount(3, $online);
        self::assertContains('user-1', $online);
        self::assertContains('user-2', $online);
        self::assertContains('user-3', $online);
    }

    public function testGetOnlineUsersAfterSomeGoOffline(): void
    {
        $service = new InMemoryPresenceService();
        $service->setOnline('user-1');
        $service->setOnline('user-2');
        $service->setOnline('user-3');
        $service->setOffline('user-2');

        $online = $service->getOnlineUsers();
        self::assertCount(2, $online);
        self::assertContains('user-1', $online);
        self::assertNotContains('user-2', $online);
        self::assertContains('user-3', $online);
    }

    public function testDuplicateSetOnlineIsIdempotent(): void
    {
        $service = new InMemoryPresenceService();
        $service->setOnline('user-1');
        $service->setOnline('user-1');

        self::assertCount(1, $service->getOnlineUsers());
    }

    public function testGetOnlineUsersReturnsEmptyWhenNoneOnline(): void
    {
        $service = new InMemoryPresenceService();

        self::assertSame([], $service->getOnlineUsers());
    }
}
