<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\Channel;
use Pulsar\Broadcasting\PresenceChannel;
use Pulsar\Broadcasting\PrivateChannel;

final class ChannelTest extends TestCase
{
    #[Test]
    public function publicChannelDoesNotRequireAuth(): void
    {
        $channel = new Channel('updates');

        self::assertFalse($channel->requiresAuth());
        self::assertSame('updates', $channel->channelName());
    }

    #[Test]
    public function privateChannelRequiresAuth(): void
    {
        $channel = new PrivateChannel('orders');

        self::assertTrue($channel->requiresAuth());
        self::assertSame('private-orders', $channel->channelName());
    }

    #[Test]
    public function presenceChannelRequiresAuth(): void
    {
        $channel = new PresenceChannel('chat-room');

        self::assertTrue($channel->requiresAuth());
        self::assertSame('presence-chat-room', $channel->channelName());
    }

    #[Test]
    public function publicChannelNameProperty(): void
    {
        $channel = new Channel('my-events');

        self::assertSame('my-events', $channel->name);
    }

    #[Test]
    public function privateChannelNameProperty(): void
    {
        $channel = new PrivateChannel('user.42');

        self::assertSame('user.42', $channel->name);
    }

    #[Test]
    public function presenceChannelNameProperty(): void
    {
        $channel = new PresenceChannel('lobby');

        self::assertSame('lobby', $channel->name);
    }
}
