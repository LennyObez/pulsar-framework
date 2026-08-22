<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\WebSocket\WebSocketConnection;

#[CoversClass(WebSocketConnection::class)]
final class WebSocketConnectionTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $conn = new WebSocketConnection('abc-123', 1710000000.0);

        self::assertSame('abc-123', $conn->id);
        self::assertSame(1710000000.0, $conn->connectedAt);
        self::assertNull($conn->userId());
        self::assertFalse($conn->isAuthenticated());
    }

    #[Test]
    public function authenticateSetsUserId(): void
    {
        $conn = new WebSocketConnection('abc', 0.0);
        $conn->authenticate('user-42');

        self::assertSame('user-42', $conn->userId());
        self::assertTrue($conn->isAuthenticated());
    }

    #[Test]
    public function subscribeAndUnsubscribe(): void
    {
        $conn = new WebSocketConnection('abc', 0.0);

        $conn->subscribe('chat');
        $conn->subscribe('news');

        self::assertTrue($conn->isSubscribedTo('chat'));
        self::assertTrue($conn->isSubscribedTo('news'));
        self::assertCount(2, $conn->channels());

        $conn->unsubscribe('chat');

        self::assertFalse($conn->isSubscribedTo('chat'));
        self::assertTrue($conn->isSubscribedTo('news'));
        self::assertCount(1, $conn->channels());
    }

    #[Test]
    public function subscribeIsIdempotent(): void
    {
        $conn = new WebSocketConnection('abc', 0.0);

        $conn->subscribe('chat');
        $conn->subscribe('chat');

        self::assertCount(1, $conn->channels());
    }

    #[Test]
    public function unsubscribeNonexistentChannelIsNoop(): void
    {
        $conn = new WebSocketConnection('abc', 0.0);
        $conn->unsubscribe('nonexistent');

        self::assertSame([], $conn->channels());
    }

    #[Test]
    public function metadata(): void
    {
        $conn = new WebSocketConnection('abc', 0.0);

        self::assertNull($conn->getMeta('ip'));
        self::assertSame('default', $conn->getMeta('ip', 'default'));

        $conn->setMeta('ip', '192.168.1.1');
        self::assertSame('192.168.1.1', $conn->getMeta('ip'));
    }

    #[Test]
    public function channelsReturnsList(): void
    {
        $conn = new WebSocketConnection('abc', 0.0);
        $conn->subscribe('a');
        $conn->subscribe('b');

        $channels = $conn->channels();

        self::assertSame(['a', 'b'], $channels);
    }

    #[Test]
    public function constructWithUserId(): void
    {
        $conn = new WebSocketConnection('abc', 0.0, 'user-1');

        self::assertSame('user-1', $conn->userId());
        self::assertTrue($conn->isAuthenticated());
    }
}
