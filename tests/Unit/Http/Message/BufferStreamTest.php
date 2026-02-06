<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\BufferStream;
use Pulsar\Http\Message\Stream;

#[CoversClass(BufferStream::class)]
#[CoversClass(Stream::class)]
final class BufferStreamTest extends TestCase
{
    #[Test]
    public function emptyBufferStreamIsReadableWritableSeekable(): void
    {
        $stream = new BufferStream();

        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isSeekable());
        self::assertSame('', (string) $stream);
    }

    #[Test]
    public function bufferStreamWithContent(): void
    {
        $stream = new BufferStream('Hello, Buffer!');

        self::assertSame(0, $stream->tell());
        self::assertSame('Hello, Buffer!', (string) $stream);
    }

    #[Test]
    public function bufferStreamIsRewindable(): void
    {
        $stream = new BufferStream('content');

        (void) $stream->read(3);
        self::assertSame(3, $stream->tell());

        $stream->rewind();
        self::assertSame(0, $stream->tell());
        self::assertSame('content', $stream->getContents());
    }

    #[Test]
    public function fromStreamCopiesContents(): void
    {
        $source = Stream::create('source content');

        $buffer = BufferStream::fromStream($source);

        self::assertSame(0, $buffer->tell());
        self::assertSame('source content', (string) $buffer);
    }

    #[Test]
    public function fromStreamRewindsSeekableSource(): void
    {
        $source = Stream::create('full content');
        $source->seek(5);

        $buffer = BufferStream::fromStream($source);

        self::assertSame('full content', (string) $buffer);
    }

    #[Test]
    public function writeAndReadBack(): void
    {
        $stream = new BufferStream();

        $stream->write('first ');
        $stream->write('second');

        $stream->rewind();
        self::assertSame('first second', $stream->getContents());
    }

    #[Test]
    public function getSizeReturnsContentLength(): void
    {
        $stream = new BufferStream('12345');

        self::assertSame(5, $stream->getSize());
    }
}
