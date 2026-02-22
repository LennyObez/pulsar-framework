<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Streaming;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\ServerStream;

#[CoversClass(ServerStream::class)]
final class ServerStreamTest extends TestCase
{
    #[Test]
    public function startsOpen(): void
    {
        $stream = new ServerStream();

        self::assertFalse($stream->isClosed());
    }

    #[Test]
    public function readReturnsNullWithNoClientRequest(): void
    {
        $stream = new ServerStream();

        self::assertNull($stream->read());
    }

    #[Test]
    public function readReturnsClientRequestOnce(): void
    {
        $stream = new ServerStream();
        $stream->setClientRequest('request-payload');

        self::assertSame('request-payload', $stream->read());
        self::assertNull($stream->read());
    }

    #[Test]
    public function writeBuffersMessages(): void
    {
        $stream = new ServerStream();
        $stream->write('msg-1');
        $stream->write('msg-2');

        self::assertSame(2, $stream->bufferSize());
    }

    #[Test]
    public function receiveConsumesBufferedMessages(): void
    {
        $stream = new ServerStream();
        $stream->write('msg-1');
        $stream->write('msg-2');

        self::assertSame('msg-1', $stream->receive());
        self::assertSame('msg-2', $stream->receive());
        self::assertNull($stream->receive());
    }

    #[Test]
    public function receiveReturnsNullWhenEmpty(): void
    {
        $stream = new ServerStream();

        self::assertNull($stream->receive());
    }

    #[Test]
    public function closeMarksStreamAsClosed(): void
    {
        $stream = new ServerStream();
        $stream->close();

        self::assertTrue($stream->isClosed());
    }

    #[Test]
    public function writeAfterCloseThrows(): void
    {
        $stream = new ServerStream();
        $stream->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot write to a closed stream.');

        $stream->write('should-fail');
    }

    #[Test]
    public function bufferSizeDecreasesAfterReceive(): void
    {
        $stream = new ServerStream();
        $stream->write('msg-1');
        $stream->write('msg-2');
        $stream->write('msg-3');

        self::assertSame(3, $stream->bufferSize());

        $stream->receive();

        self::assertSame(2, $stream->bufferSize());
    }

    #[Test]
    public function bufferSizeIsZeroWhenEmpty(): void
    {
        $stream = new ServerStream();

        self::assertSame(0, $stream->bufferSize());
    }
}
