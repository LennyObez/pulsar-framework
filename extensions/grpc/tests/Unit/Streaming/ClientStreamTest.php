<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Streaming;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\ClientStream;

#[CoversClass(ClientStream::class)]
final class ClientStreamTest extends TestCase
{
    #[Test]
    public function startsOpen(): void
    {
        $stream = new ClientStream();

        self::assertFalse($stream->isClosed());
    }

    #[Test]
    public function readReturnsNullWhenEmpty(): void
    {
        $stream = new ClientStream();

        self::assertNull($stream->read());
    }

    #[Test]
    public function writeAndReadMessages(): void
    {
        $stream = new ClientStream();
        $stream->write('msg-1');
        $stream->write('msg-2');

        self::assertSame('msg-1', $stream->read());
        self::assertSame('msg-2', $stream->read());
        self::assertNull($stream->read());
    }

    #[Test]
    public function messageCountTracksTotal(): void
    {
        $stream = new ClientStream();
        $stream->write('msg-1');
        $stream->write('msg-2');

        self::assertSame(2, $stream->messageCount());

        // Reading does not decrement messageCount
        $stream->read();
        self::assertSame(2, $stream->messageCount());
    }

    #[Test]
    public function pendingCountTracksPending(): void
    {
        $stream = new ClientStream();
        $stream->write('msg-1');
        $stream->write('msg-2');

        self::assertSame(2, $stream->pendingCount());

        $stream->read();

        self::assertSame(1, $stream->pendingCount());
    }

    #[Test]
    public function closeMarksStreamClosed(): void
    {
        $stream = new ClientStream();
        $stream->close();

        self::assertTrue($stream->isClosed());
    }

    #[Test]
    public function writeAfterCloseThrows(): void
    {
        $stream = new ClientStream();
        $stream->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot write to a closed stream.');

        $stream->write('should-fail');
    }

    #[Test]
    public function readAfterCloseStillDrainsBuffer(): void
    {
        $stream = new ClientStream();
        $stream->write('msg-1');
        $stream->close();

        self::assertSame('msg-1', $stream->read());
        self::assertNull($stream->read());
    }

    #[Test]
    public function messageCountStartsAtZero(): void
    {
        $stream = new ClientStream();

        self::assertSame(0, $stream->messageCount());
    }

    #[Test]
    public function pendingCountStartsAtZero(): void
    {
        $stream = new ClientStream();

        self::assertSame(0, $stream->pendingCount());
    }
}
