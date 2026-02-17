<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Broadcasting\BroadcastEventInterface;
use Pulsar\Broadcasting\BroadcastManager;
use Pulsar\Broadcasting\Channel;
use Pulsar\Broadcasting\PresenceChannel;
use Pulsar\Broadcasting\PrivateChannel;
use Pulsar\WebSocket\BroadcastManagerInterface as Transport;
use RuntimeException;

final class BroadcastManagerTest extends TestCase
{
    #[Test]
    public function broadcastDispatchesToAllChannels(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::exactly(2))->method('broadcast')->willReturnCallback(
            function (string $channel, string $event, array $data): void {
                self::assertContains($channel, ['updates', 'private-orders']);
                self::assertSame('order.placed', $event);
                self::assertSame(['id' => 1], $data);
            },
        );

        $manager = new BroadcastManager($transport);

        $event = new class implements BroadcastEventInterface {
            public function broadcastOn(): array
            {
                return [new Channel('updates'), new PrivateChannel('orders')];
            }

            public function broadcastAs(): string
            {
                return 'order.placed';
            }

            public function broadcastWith(): array
            {
                return ['id' => 1];
            }
        };

        $manager->broadcast($event);
    }

    #[Test]
    public function broadcastToSendsToSpecificChannel(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())->method('broadcast')
            ->with('presence-chat', 'message', ['text' => 'hello']);

        $manager = new BroadcastManager($transport);
        $manager->broadcastTo(new PresenceChannel('chat'), 'message', ['text' => 'hello']);
    }

    #[Test]
    public function sendToUserDelegatesToTransport(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())->method('sendTo')
            ->with('conn-42', 'notification', ['msg' => 'hi']);

        $manager = new BroadcastManager($transport);
        $manager->sendToUser('conn-42', 'notification', ['msg' => 'hi']);
    }

    #[Test]
    public function broadcastLogsErrorOnTransportFailure(): void
    {
        $transport = $this->createStub(Transport::class);
        $transport->method('broadcast')->willThrowException(new RuntimeException('connection lost'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('Failed to broadcast'),
        );

        $manager = new BroadcastManager($transport, $logger);

        $event = new class implements BroadcastEventInterface {
            public function broadcastOn(): array
            {
                return [new Channel('test')];
            }

            public function broadcastAs(): string
            {
                return 'test.event';
            }

            public function broadcastWith(): array
            {
                return [];
            }
        };

        // Should not throw — errors are logged, not propagated
        $manager->broadcast($event);
    }
}
