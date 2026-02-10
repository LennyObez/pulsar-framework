<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Streaming;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\BidirectionalStream;

#[CoversClass(BidirectionalStream::class)]
final class BidirectionalStreamTest extends TestCase
{
    #[Test]
    public function startsOpen(): void
    {
        $stream = new BidirectionalStream();

        self::assertFalse($stream->isClosed());
    }

    #[Test]
    public function readReturnsNullWhenEmpty(): void
    {
        $stream = new BidirectionalStream();

        self::assertNull($stream->read());
    }

    #[Test]
    public function pushInboundAndRead(): void
    {
        $stream = new BidirectionalStream();
        $stream->pushInbound('inbound-1');
        $stream->pushInbound('inbound-2');

        self::assertSame('inbound-1', $stream->read());
        self::assertSame('inbound-2', $stream->read());
        self::assertNull($stream->read());
    }

    #[Test]
    public function writeAndPullOutbound(): void
    {
        $stream = new BidirectionalStream();
        $stream->write('outbound-1');
        $stream->write('outbound-2');

        self::assertSame('outbound-1', $stream->pullOutbound());
        self::assertSame('outbound-2', $stream->pullOutbound());
        self::assertNull($stream->pullOutbound());
    }

    #[Test]
    public function readAndWriteBuffersAreIndependent(): void
    {
        $stream = new BidirectionalStream();
        $stream->pushInbound('inbound');
        $stream->write('outbound');

        self::assertSame(1, $stream->readBufferSize());
        self::assertSame(1, $stream->writeBufferSize());

        self::assertSame('inbound', $stream->read());
        self::assertSame('outbound', $stream->pullOutbound());
    }

    #[Test]
    public function closeMarksStreamClosed(): void
    {
        $stream = new BidirectionalStream();
        $stream->close();

        self::assertTrue($stream->isClosed());
    }

    #[Test]
    public function writeAfterCloseThrows(): void
    {
        $stream = new BidirectionalStream();
        $stream->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot write to a closed stream.');

        $stream->write('should-fail');
    }

    #[Test]
    public function readBufferSizeTracksInbound(): void
    {
        $stream = new BidirectionalStream();

        self::assertSame(0, $stream->readBufferSize());

        $stream->pushInbound('msg-1');
        $stream->pushInbound('msg-2');

        self::assertSame(2, $stream->readBufferSize());

        $stream->read();

        self::assertSame(1, $stream->readBufferSize());
    }

    #[Test]
    public function writeBufferSizeTracksOutbound(): void
    {
        $stream = new BidirectionalStream();

        self::assertSame(0, $stream->writeBufferSize());

        $stream->write('msg-1');
        $stream->write('msg-2');
        $stream->write('msg-3');

        self::assertSame(3, $stream->writeBufferSize());

        $stream->pullOutbound();

        self::assertSame(2, $stream->writeBufferSize());
    }

    #[Test]
    public function pullOutboundReturnsNullWhenEmpty(): void
    {
        $stream = new BidirectionalStream();

        self::assertNull($stream->pullOutbound());
    }

    #[Test]
    public function pushInboundWorksAfterClose(): void
    {
        $stream = new BidirectionalStream();
        $stream->close();

        // Inbound messages can still arrive after close (remote may send before seeing close)
        $stream->pushInbound('late-msg');

        self::assertSame('late-msg', $stream->read());
    }
}
