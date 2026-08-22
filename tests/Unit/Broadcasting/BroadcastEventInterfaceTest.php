<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\BroadcastEventInterface;
use Pulsar\Broadcasting\Channel;

/**
 * Tests the BroadcastEventInterface contract via anonymous implementation.
 */
#[CoversClass(Channel::class)]
final class BroadcastEventInterfaceTest extends TestCase
{
    #[Test]
    public function implementationReturnsBroadcastChannels(): void
    {
        // Arrange
        $event = $this->createConcreteEvent(
            channels: [new Channel('orders'), new Channel('admin')],
            eventName: 'order.created',
            payload: ['order_id' => 42],
        );

        // Act
        $channels = $event->broadcastOn();

        // Assert
        self::assertCount(2, $channels);
        self::assertSame('orders', $channels[0]->name);
        self::assertSame('admin', $channels[1]->name);
    }

    #[Test]
    public function implementationReturnsBroadcastEventName(): void
    {
        // Arrange
        $event = $this->createConcreteEvent(
            channels: [],
            eventName: 'user.registered',
            payload: [],
        );

        // Act & Assert
        self::assertSame('user.registered', $event->broadcastAs());
    }

    #[Test]
    public function implementationReturnsPayloadData(): void
    {
        // Arrange
        $payload = ['user_id' => 7, 'email' => 'test@example.com'];
        $event = $this->createConcreteEvent(
            channels: [],
            eventName: 'user.updated',
            payload: $payload,
        );

        // Act & Assert
        self::assertSame($payload, $event->broadcastWith());
    }

    #[Test]
    public function implementationCanReturnEmptyChannelList(): void
    {
        // Arrange
        $event = $this->createConcreteEvent(
            channels: [],
            eventName: 'noop',
            payload: [],
        );

        // Act & Assert
        self::assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function implementationCanReturnEmptyPayload(): void
    {
        // Arrange
        $event = $this->createConcreteEvent(
            channels: [new Channel('notifications')],
            eventName: 'ping',
            payload: [],
        );

        // Act & Assert
        self::assertSame([], $event->broadcastWith());
    }

    #[Test]
    public function stubSatisfiesInterfaceContract(): void
    {
        // Arrange
        $stub = $this->createStub(BroadcastEventInterface::class);
        $stub->method('broadcastOn')->willReturn([new Channel('ch1')]);
        $stub->method('broadcastAs')->willReturn('test.event');
        $stub->method('broadcastWith')->willReturn(['key' => 'value']);

        // Act & Assert
        self::assertCount(1, $stub->broadcastOn());
        self::assertSame('test.event', $stub->broadcastAs());
        self::assertSame(['key' => 'value'], $stub->broadcastWith());
    }

    /**
     * @param list<Channel> $channels
     * @param array<string, mixed> $payload
     */
    private function createConcreteEvent(array $channels, string $eventName, array $payload): BroadcastEventInterface
    {
        return new class ($channels, $eventName, $payload) implements BroadcastEventInterface {
            /**
             * @param list<Channel> $channels
             * @param array<string, mixed> $payload
             */
            public function __construct(
                private readonly array $channels,
                private readonly string $eventName,
                private readonly array $payload,
            ) {}

            public function broadcastOn(): array
            {
                return $this->channels;
            }

            public function broadcastAs(): string
            {
                return $this->eventName;
            }

            public function broadcastWith(): array
            {
                return $this->payload;
            }
        };
    }
}
