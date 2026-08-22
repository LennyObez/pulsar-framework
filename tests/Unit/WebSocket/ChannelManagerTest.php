<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\ChannelManager;

#[CoversClass(ChannelManager::class)]
final class ChannelManagerTest extends TestCase
{
    #[Test]
    public function subscribeAddsConnection(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');

        self::assertSame(['conn-1'], $manager->subscribers('chat'));
        self::assertSame(1, $manager->subscriberCount('chat'));
        self::assertTrue($manager->hasSubscribers('chat'));
    }

    #[Test]
    public function subscribeIsIdempotent(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');
        $manager->subscribe('chat', 'conn-1');

        self::assertSame(['conn-1'], $manager->subscribers('chat'));
    }

    #[Test]
    public function multipleSubscribers(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');
        $manager->subscribe('chat', 'conn-2');
        $manager->subscribe('chat', 'conn-3');

        self::assertCount(3, $manager->subscribers('chat'));
        self::assertSame(3, $manager->subscriberCount('chat'));
    }

    #[Test]
    public function unsubscribeRemovesConnection(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');
        $manager->subscribe('chat', 'conn-2');

        $manager->unsubscribe('chat', 'conn-1');

        self::assertSame(['conn-2'], $manager->subscribers('chat'));
    }

    #[Test]
    public function unsubscribeLastRemovesChannel(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');
        $manager->unsubscribe('chat', 'conn-1');

        self::assertFalse($manager->hasSubscribers('chat'));
        self::assertSame([], $manager->subscribers('chat'));
    }

    #[Test]
    public function unsubscribeFromNonExistentChannelIsNoop(): void
    {
        $manager = new ChannelManager();
        $manager->unsubscribe('nonexistent', 'conn-1');

        self::assertSame([], $manager->subscribers('nonexistent'));
    }

    #[Test]
    public function unsubscribeAllRemovesFromAllChannels(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');
        $manager->subscribe('news', 'conn-1');
        $manager->subscribe('chat', 'conn-2');

        $removed = $manager->unsubscribeAll('conn-1');

        self::assertContains('chat', $removed);
        self::assertContains('news', $removed);
        self::assertSame(['conn-2'], $manager->subscribers('chat'));
        self::assertFalse($manager->hasSubscribers('news'));
    }

    #[Test]
    public function activeChannels(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('chat', 'conn-1');
        $manager->subscribe('news', 'conn-2');

        $channels = $manager->activeChannels();

        self::assertContains('chat', $channels);
        self::assertContains('news', $channels);
    }

    #[Test]
    public function presenceSubscription(): void
    {
        $manager = new ChannelManager();
        $userInfo = ['id' => '123', 'name' => 'Alice'];

        $manager->subscribePresence('presence-editors', 'conn-1', $userInfo);

        self::assertSame(['conn-1'], $manager->subscribers('presence-editors'));

        $members = $manager->presenceMembers('presence-editors');
        self::assertSame($userInfo, $members['conn-1']);
    }

    #[Test]
    public function presenceUnsubscribeCleansUpData(): void
    {
        $manager = new ChannelManager();
        $manager->subscribePresence('presence-editors', 'conn-1', ['id' => '1']);
        $manager->subscribePresence('presence-editors', 'conn-2', ['id' => '2']);

        $manager->unsubscribe('presence-editors', 'conn-1');

        $members = $manager->presenceMembers('presence-editors');
        self::assertArrayNotHasKey('conn-1', $members);
        self::assertArrayHasKey('conn-2', $members);
    }

    #[Test]
    public function presenceUnsubscribeLastCleansUpChannel(): void
    {
        $manager = new ChannelManager();
        $manager->subscribePresence('presence-editors', 'conn-1', ['id' => '1']);
        $manager->unsubscribe('presence-editors', 'conn-1');

        self::assertSame([], $manager->presenceMembers('presence-editors'));
    }

    #[Test]
    public function requiresAuth(): void
    {
        self::assertTrue(ChannelManager::requiresAuth('private-chat'));
        self::assertTrue(ChannelManager::requiresAuth('presence-editors'));
        self::assertFalse(ChannelManager::requiresAuth('public-news'));
        self::assertFalse(ChannelManager::requiresAuth('chat'));
    }

    #[Test]
    public function isPresenceChannel(): void
    {
        self::assertTrue(ChannelManager::isPresenceChannel('presence-editors'));
        self::assertFalse(ChannelManager::isPresenceChannel('private-chat'));
        self::assertFalse(ChannelManager::isPresenceChannel('public'));
    }

    #[Test]
    public function isPrivateChannel(): void
    {
        self::assertTrue(ChannelManager::isPrivateChannel('private-chat'));
        self::assertFalse(ChannelManager::isPrivateChannel('presence-editors'));
        self::assertFalse(ChannelManager::isPrivateChannel('public'));
    }

    #[Test]
    public function subscriberCountForEmptyChannel(): void
    {
        $manager = new ChannelManager();

        self::assertSame(0, $manager->subscriberCount('nonexistent'));
    }

    #[Test]
    public function presenceMembersForNonPresenceChannel(): void
    {
        $manager = new ChannelManager();
        $manager->subscribe('regular', 'conn-1');

        self::assertSame([], $manager->presenceMembers('regular'));
    }
}
