<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\Channel;
use Pulsar\Broadcasting\PrivateChannel;

#[CoversClass(PrivateChannel::class)]
final class PrivateChannelTest extends TestCase
{
    #[Test]
    public function requiresAuthentication(): void
    {
        $channel = new PrivateChannel('orders');

        self::assertTrue($channel->requiresAuth());
    }

    #[Test]
    public function channelNameHasPrivatePrefix(): void
    {
        $channel = new PrivateChannel('orders');

        self::assertSame('private-orders', $channel->channelName());
    }

    #[Test]
    public function namePropertyHoldsRawValue(): void
    {
        $channel = new PrivateChannel('user.99');

        self::assertSame('user.99', $channel->name);
    }

    #[Test]
    public function extendsBaseChannel(): void
    {
        $channel = new PrivateChannel('secret');

        self::assertInstanceOf(Channel::class, $channel);
    }

    #[Test]
    #[DataProvider('channelNameProvider')]
    public function prefixIsAppliedToVariousNames(string $raw, string $expected): void
    {
        $channel = new PrivateChannel($raw);

        self::assertSame($expected, $channel->channelName());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function channelNameProvider(): iterable
    {
        yield 'simple name' => ['orders', 'private-orders'];
        yield 'dotted name' => ['user.42', 'private-user.42'];
        yield 'nested dots' => ['org.team.data', 'private-org.team.data'];
        yield 'with hyphens' => ['my-channel', 'private-my-channel'];
    }
}
