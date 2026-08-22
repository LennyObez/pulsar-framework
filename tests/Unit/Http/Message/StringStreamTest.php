<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Pulsar\Http\Message\StringStream;
use RuntimeException;

#[CoversClass(StringStream::class)]
final class StringStreamTest extends TestCase
{
    #[Test]
    public function implementsStreamInterface(): void
    {
        $stream = new StringStream('hello');

        self::assertInstanceOf(StreamInterface::class, $stream);
    }

    #[Test]
    public function emptyStringStream(): void
    {
        $stream = new StringStream();

        self::assertSame('', (string) $stream);
        self::assertSame(0, $stream->getSize());
        self::assertTrue($stream->eof());
        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertTrue($stream->isSeekable());
        self::assertSame(0, $stream->tell());
    }

    #[Test]
    public function toStringReturnsFullContent(): void
    {
        $stream = new StringStream('Hello, World!');

        self::assertSame('Hello, World!', (string) $stream);
        self::assertSame(13, $stream->getSize());
    }

    #[Test]
    public function readReturnsRequestedBytes(): void
    {
        $stream = new StringStream('Hello, World!');

        self::assertSame('Hello', $stream->read(5));
        self::assertSame(5, $stream->tell());
        self::assertSame(', ', $stream->read(2));
        self::assertSame(7, $stream->tell());
    }

    #[Test]
    public function getContentsReturnsRemainingContent(): void
    {
        $stream = new StringStream('Hello, World!');

        (void) $stream->read(7);

        self::assertSame('World!', $stream->getContents());
    }

    #[Test]
    public function seekAndRewind(): void
    {
        $stream = new StringStream('Hello, World!');

        $stream->seek(7);
        self::assertSame(7, $stream->tell());
        self::assertSame('World!', $stream->getContents());

        $stream->rewind();
        self::assertSame(0, $stream->tell());
        self::assertSame('Hello, World!', $stream->getContents());
    }

    #[Test]
    public function seekFromCurrent(): void
    {
        $stream = new StringStream('Hello, World!');

        (void) $stream->read(5);
        $stream->seek(2, SEEK_CUR);

        self::assertSame(7, $stream->tell());
    }

    #[Test]
    public function seekFromEnd(): void
    {
        $stream = new StringStream('Hello, World!');

        $stream->seek(-6, SEEK_END);

        self::assertSame('World!', $stream->getContents());
    }

    #[Test]
    public function eofReturnsTrueAtEnd(): void
    {
        $stream = new StringStream('ab');

        self::assertFalse($stream->eof());
        (void) $stream->read(2);
        self::assertTrue($stream->eof());
    }

    #[Test]
    public function writeThrowsException(): void
    {
        $stream = new StringStream('test');

        $this->expectException(RuntimeException::class);
        $stream->write('data');
    }

    #[Test]
    public function closeDetachesContent(): void
    {
        $stream = new StringStream('test');

        $stream->close();

        self::assertNull($stream->getSize());
        self::assertSame('', (string) $stream);
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isSeekable());
    }

    #[Test]
    public function detachReturnsNull(): void
    {
        $stream = new StringStream('test');

        $stream->detach();
        self::assertNull($stream->getSize());
    }

    #[Test]
    public function readAfterDetachThrows(): void
    {
        $stream = new StringStream('test');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        (void) $stream->read(1);
    }

    #[Test]
    public function getContentsAfterDetachThrows(): void
    {
        $stream = new StringStream('test');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        (void) $stream->getContents();
    }

    #[Test]
    public function tellAfterDetachThrows(): void
    {
        $stream = new StringStream('test');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        (void) $stream->tell();
    }

    #[Test]
    public function seekAfterDetachThrows(): void
    {
        $stream = new StringStream('test');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $stream->seek(0);
    }

    #[Test]
    public function getMetadataReturnsExpectedKeys(): void
    {
        $stream = new StringStream('test');

        $meta = $stream->getMetadata();
        self::assertIsArray($meta);
        self::assertSame('MEMORY', $meta['stream_type']);
        self::assertSame('r', $meta['mode']);
        self::assertTrue($meta['seekable']);

        self::assertSame('MEMORY', $stream->getMetadata('stream_type'));
        self::assertNull($stream->getMetadata('nonexistent'));
    }

    #[Test]
    public function seekClampsToValidRange(): void
    {
        $stream = new StringStream('test');

        $stream->seek(-100, SEEK_SET);
        self::assertSame(0, $stream->tell());

        $stream->seek(100, SEEK_SET);
        self::assertSame(4, $stream->tell());
    }
}
