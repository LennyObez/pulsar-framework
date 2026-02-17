<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\BroadcastEvent;

#[CoversClass(BroadcastEvent::class)]
final class BroadcastEventTest extends TestCase
{
    #[Test]
    public function resolvePublicChannel(): void
    {
        $attr = new BroadcastEvent(channel: 'orders.{orderId}');
        $resolved = $attr->resolveChannel(['orderId' => '42']);

        self::assertSame('orders.42', $resolved);
    }

    #[Test]
    public function resolvePrivateChannel(): void
    {
        $attr = new BroadcastEvent(channel: 'chat.{roomId}', private: true);
        $resolved = $attr->resolveChannel(['roomId' => '7']);

        self::assertSame('private-chat.7', $resolved);
    }

    #[Test]
    public function resolvePresenceChannel(): void
    {
        $attr = new BroadcastEvent(channel: 'editors.{docId}', presence: true);
        $resolved = $attr->resolveChannel(['docId' => 'abc']);

        self::assertSame('presence-editors.abc', $resolved);
    }

    #[Test]
    public function presenceTakesPrecedenceOverPrivate(): void
    {
        $attr = new BroadcastEvent(channel: 'room', private: true, presence: true);
        $resolved = $attr->resolveChannel([]);

        self::assertSame('presence-room', $resolved);
    }

    #[Test]
    public function resolveWithMultipleParams(): void
    {
        $attr = new BroadcastEvent(channel: 'team.{teamId}.project.{projectId}');
        $resolved = $attr->resolveChannel(['teamId' => '1', 'projectId' => '99']);

        self::assertSame('team.1.project.99', $resolved);
    }

    #[Test]
    public function resolveWithNoParams(): void
    {
        $attr = new BroadcastEvent(channel: 'global-notifications');
        $resolved = $attr->resolveChannel([]);

        self::assertSame('global-notifications', $resolved);
    }

    #[Test]
    public function unresolvedPlaceholdersRemain(): void
    {
        $attr = new BroadcastEvent(channel: 'orders.{orderId}');
        $resolved = $attr->resolveChannel([]);

        self::assertSame('orders.{orderId}', $resolved);
    }
}
