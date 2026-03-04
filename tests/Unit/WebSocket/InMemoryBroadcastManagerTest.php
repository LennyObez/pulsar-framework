<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\ChannelManager;
use Pulsar\WebSocket\Internal\InMemoryBroadcastManager;

use function ord;

#[CoversClass(InMemoryBroadcastManager::class)]
final class InMemoryBroadcastManagerTest extends TestCase
{
    #[Test]
    public function broadcastSendsToAllSubscribers(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        $received1 = [];
        $received2 = [];

        $channelManager->subscribe('chat', 'conn-1');
        $channelManager->subscribe('chat', 'conn-2');

        $manager->registerSender('conn-1', function (string $data) use (&$received1): void {
            $received1[] = $data;
        });
        $manager->registerSender('conn-2', function (string $data) use (&$received2): void {
            $received2[] = $data;
        });

        $manager->broadcast('chat', 'message', ['text' => 'hello']);

        self::assertCount(1, $received1);
        self::assertCount(1, $received2);
    }

    #[Test]
    public function broadcastExceptExcludesConnections(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        $received1 = [];
        $received2 = [];

        $channelManager->subscribe('chat', 'conn-1');
        $channelManager->subscribe('chat', 'conn-2');

        $manager->registerSender('conn-1', function (string $data) use (&$received1): void {
            $received1[] = $data;
        });
        $manager->registerSender('conn-2', function (string $data) use (&$received2): void {
            $received2[] = $data;
        });

        $manager->broadcastExcept('chat', 'message', ['text' => 'hi'], ['conn-1']);

        self::assertCount(0, $received1);
        self::assertCount(1, $received2);
    }

    #[Test]
    public function sendToTargetsSpecificConnection(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        $received = [];

        $manager->registerSender('conn-1', function (string $data) use (&$received): void {
            $received[] = $data;
        });

        $manager->sendTo('conn-1', 'notification', ['msg' => 'hi']);

        self::assertCount(1, $received);
    }

    #[Test]
    public function sendToNonexistentConnectionIsNoop(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        // Should not throw
        $manager->sendTo('nonexistent', 'event', []);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function removeSenderStopsDelivery(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        $received = [];

        $channelManager->subscribe('chat', 'conn-1');
        $manager->registerSender('conn-1', function (string $data) use (&$received): void {
            $received[] = $data;
        });

        $manager->removeSender('conn-1');
        $manager->broadcast('chat', 'msg', []);

        self::assertCount(0, $received);
    }

    #[Test]
    public function broadcastToEmptyChannelIsNoop(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        // Should not throw
        $manager->broadcast('empty', 'event', ['data' => 'value']);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function broadcastPayloadIsValidWebSocketFrame(): void
    {
        $channelManager = new ChannelManager();
        $manager = new InMemoryBroadcastManager($channelManager);

        $received = '';

        $channelManager->subscribe('chat', 'conn-1');
        $manager->registerSender('conn-1', function (string $data) use (&$received): void {
            $received = $data;
        });

        $manager->broadcast('chat', 'message', ['text' => 'hi']);

        // First byte should be 0x81 (FIN + text opcode)
        self::assertNotEmpty($received);
        self::assertSame(0x81, ord($received[0]));
    }
}
