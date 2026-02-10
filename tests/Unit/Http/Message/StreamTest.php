<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Stream;
use RuntimeException;

use function file_put_contents;
use InvalidArgumentException;

use function fopen;
use function fwrite;
use function rewind;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(Stream::class)]
final class StreamTest extends TestCase
{
    #[Test]
    public function createReturnsEmptyStream(): void
    {
        $stream = Stream::create();

        self::assertSame('', (string) $stream);
        self::assertSame(0, $stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isSeekable());
    }

    #[Test]
    public function createWithContentReturnsSeekableStream(): void
    {
        $stream = Stream::create('Hello, World!');

        self::assertSame(0, $stream->tell());
        self::assertSame(13, $stream->getSize());
        self::assertSame('Hello, World!', (string) $stream);
    }

    #[Test]
    public function fromFileOpensFileStream(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'file content');

        try {
            $stream = Stream::fromFile($tmpFile, 'r');

            self::assertSame('file content', (string) $stream);
            self::assertTrue($stream->isReadable());
            self::assertFalse($stream->isWritable());

            $stream->close();
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function fromFileThrowsForNonexistentFile(): void
    {
        $this->expectException(RuntimeException::class);

        (void) Stream::fromFile('/nonexistent/path/file.txt', 'r');
    }

    #[Test]
    public function readReturnsRequestedBytes(): void
    {
        $stream = Stream::create('Hello, World!');

        self::assertSame('Hello', $stream->read(5));
        self::assertSame(', ', $stream->read(2));
    }

    #[Test]
    public function writeAppendsToStream(): void
    {
        $stream = Stream::create();
        $bytes = $stream->write('Hello');

        self::assertSame(5, $bytes);

        $stream->rewind();
        self::assertSame('Hello', $stream->getContents());
    }

    #[Test]
    public function seekMovesPosition(): void
    {
        $stream = Stream::create('Hello, World!');

        $stream->seek(7);
        self::assertSame(7, $stream->tell());
        self::assertSame('World!', $stream->getContents());
    }

    #[Test]
    public function rewindResetsPosition(): void
    {
        $stream = Stream::create('Hello');

        (void) $stream->read(3);
        self::assertSame(3, $stream->tell());

        $stream->rewind();
        self::assertSame(0, $stream->tell());
    }

    #[Test]
    public function eofReturnsTrueAtEnd(): void
    {
        $stream = Stream::create('Hi');

        self::assertFalse($stream->eof());

        (void) $stream->read(2);
        (void) $stream->read(1);

        self::assertTrue($stream->eof());
    }

    #[Test]
    public function getContentsReturnsRemainingContent(): void
    {
        $stream = Stream::create('Hello, World!');

        $stream->seek(7);
        self::assertSame('World!', $stream->getContents());
    }

    #[Test]
    public function toStringReturnsFullContents(): void
    {
        $stream = Stream::create('Hello, World!');

        $stream->seek(7);
        self::assertSame('Hello, World!', (string) $stream);
    }

    #[Test]
    public function toStringReturnsEmptyOnDetachedStream(): void
    {
        $stream = Stream::create('Hello');
        $stream->detach();

        self::assertSame('', (string) $stream);
    }

    #[Test]
    public function closeReleasesResource(): void
    {
        $stream = Stream::create('Hello');
        $stream->close();

        self::assertNull($stream->getSize());
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
    }

    #[Test]
    public function detachReturnsResourceAndResetsState(): void
    {
        $stream = Stream::create('Hello');
        $resource = $stream->detach();

        self::assertIsResource($resource);
        self::assertNull($stream->detach());
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
    }

    #[Test]
    public function tellThrowsOnDetachedStream(): void
    {
        $stream = Stream::create('Hello');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');

        (void) $stream->tell();
    }

    #[Test]
    public function readThrowsOnDetachedStream(): void
    {
        $stream = Stream::create('Hello');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');

        (void) $stream->read(1);
    }

    #[Test]
    public function writeThrowsOnDetachedStream(): void
    {
        $stream = Stream::create('Hello');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');

        $stream->write('x');
    }

    #[Test]
    public function seekThrowsOnDetachedStream(): void
    {
        $stream = Stream::create('Hello');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');

        $stream->seek(0);
    }

    #[Test]
    public function getContentsThrowsOnDetachedStream(): void
    {
        $stream = Stream::create('Hello');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');

        (void) $stream->getContents();
    }

    #[Test]
    public function readThrowsOnNonReadableStream(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);

        try {
            $stream = Stream::fromFile($tmpFile, 'w');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Stream is not readable');

            (void) $stream->read(1);
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function writeThrowsOnNonWritableStream(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);

        try {
            $stream = Stream::fromFile($tmpFile, 'r');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Stream is not writable');

            $stream->write('data');
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function getMetadataReturnsAllMetadata(): void
    {
        $stream = Stream::create('test');
        $meta = $stream->getMetadata();

        self::assertIsArray($meta);
        self::assertArrayHasKey('mode', $meta);
        self::assertArrayHasKey('seekable', $meta);
    }

    #[Test]
    public function getMetadataReturnsSpecificKey(): void
    {
        $stream = Stream::create('test');

        self::assertTrue($stream->getMetadata('seekable'));
        self::assertNull($stream->getMetadata('nonexistent'));
    }

    #[Test]
    public function getMetadataReturnsNullForDetachedStream(): void
    {
        $stream = Stream::create('test');
        $stream->detach();

        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('seekable'));
    }

    #[Test]
    public function eofReturnsTrueOnDetachedStream(): void
    {
        $stream = Stream::create('test');
        $stream->detach();

        self::assertTrue($stream->eof());
    }

    #[Test]
    public function constructorFromResource(): void
    {
        $resource = fopen('php://temp', 'r+b');
        self::assertNotFalse($resource);
        fwrite($resource, 'test data');
        rewind($resource);

        $stream = new Stream($resource);

        self::assertSame('test data', (string) $stream);
    }

    #[Test]
    public function constructorRejectsNonResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stream requires a valid PHP resource');

        new Stream('not a resource');
    }

    #[Test]
    public function seekThrowsOnNonSeekableStream(): void
    {
        // php://output is not seekable
        $resource = fopen('php://output', 'wb');
        self::assertNotFalse($resource);

        $stream = new Stream($resource);

        self::assertFalse($stream->isSeekable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not seekable');

        $stream->seek(0);
    }

    #[Test]
    public function getContentsThrowsOnNonReadableStream(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);

        try {
            $stream = Stream::fromFile($tmpFile, 'w');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Stream is not readable');

            (void) $stream->getContents();
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function getSizeReturnsNullAfterDetach(): void
    {
        $stream = Stream::create('content');
        $stream->detach();

        self::assertNull($stream->getSize());
    }

    #[Test]
    public function closeOnAlreadyClosedStreamDoesNotThrow(): void
    {
        $stream = Stream::create('content');
        $stream->close();
        $stream->close();

        self::assertNull($stream->getSize());
    }

    #[Test]
    public function fromFileWithWriteMode(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);

        try {
            $stream = Stream::fromFile($tmpFile, 'w');

            self::assertTrue($stream->isWritable());
            self::assertFalse($stream->isReadable());

            $stream->write('hello');
            $stream->close();

            self::assertSame('hello', file_get_contents($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function fromFileWithAppendMode(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'existing');

        try {
            $stream = Stream::fromFile($tmpFile, 'a');

            self::assertTrue($stream->isWritable());

            $stream->write(' appended');
            $stream->close();

            self::assertSame('existing appended', file_get_contents($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function createWithLargeContent(): void
    {
        $content = str_repeat('x', 100_000);
        $stream = Stream::create($content);

        self::assertSame(100_000, $stream->getSize());
        self::assertSame($content, (string) $stream);
    }

    #[Test]
    public function seekWithWhenceEnd(): void
    {
        $stream = Stream::create('hello');

        $stream->seek(-3, SEEK_END);
        self::assertSame('llo', $stream->getContents());
    }

    #[Test]
    public function seekWithWhenceCurrent(): void
    {
        $stream = Stream::create('hello world');

        $stream->seek(5);
        $stream->seek(1, SEEK_CUR);
        self::assertSame('world', $stream->getContents());
    }
}
