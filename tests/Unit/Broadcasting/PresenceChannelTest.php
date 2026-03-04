<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\Channel;
use Pulsar\Broadcasting\PresenceChannel;

#[CoversClass(PresenceChannel::class)]
final class PresenceChannelTest extends TestCase
{
    #[Test]
    public function requiresAuthentication(): void
    {
        $channel = new PresenceChannel('chat-room');

        self::assertTrue($channel->requiresAuth());
    }

    #[Test]
    public function channelNameHasPresencePrefix(): void
    {
        $channel = new PresenceChannel('lobby');

        self::assertSame('presence-lobby', $channel->channelName());
    }

    #[Test]
    public function namePropertyHoldsRawValue(): void
    {
        $channel = new PresenceChannel('game.42');

        self::assertSame('game.42', $channel->name);
    }

    #[Test]
    public function extendsBaseChannel(): void
    {
        $channel = new PresenceChannel('room');

        self::assertInstanceOf(Channel::class, $channel);
    }

    #[Test]
    #[DataProvider('channelNameProvider')]
    public function prefixIsAppliedToVariousNames(string $raw, string $expected): void
    {
        $channel = new PresenceChannel($raw);

        self::assertSame($expected, $channel->channelName());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function channelNameProvider(): iterable
    {
        yield 'simple name' => ['room', 'presence-room'];
        yield 'dotted name' => ['game.42', 'presence-game.42'];
        yield 'nested dots' => ['org.team.channel', 'presence-org.team.channel'];
        yield 'with hyphens' => ['my-room', 'presence-my-room'];
    }
}
