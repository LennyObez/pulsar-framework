<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Stream;
use RuntimeException;

use function fopen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(Stream::class)]
final class StreamCoverageTest extends TestCase
{
    #[Test]
    public function toStringReturnEmptyOnRuntimeException(): void
    {
        $stream = Stream::create('content');
        $stream->detach();

        // Detached stream cannot seek, so __toString catches RuntimeException
        self::assertSame('', (string) $stream);
    }

    #[Test]
    public function getSizeReturnsNullWhenResourceDetached(): void
    {
        $stream = Stream::create('content');
        $stream->detach();

        self::assertNull($stream->getSize());
    }

    #[Test]
    public function constructorWithExclusiveCreateMode(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);
        @unlink($tmpFile); // Remove so x mode can create

        try {
            $resource = fopen($tmpFile, 'x+b');
            self::assertNotFalse($resource);

            $stream = new Stream($resource);

            // x mode: writable and readable (because of +)
            self::assertTrue($stream->isWritable());
            self::assertTrue($stream->isReadable());
        } finally {
            @unlink($tmpFile);
        }
    }

    #[Test]
    public function constructorWithCreateIfNotExistsMode(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_test_');
        self::assertNotFalse($tmpFile);

        try {
            $resource = fopen($tmpFile, 'c+b');
            self::assertNotFalse($resource);

            $stream = new Stream($resource);

            // c mode: writable and readable (because of +)
            self::assertTrue($stream->isWritable());
            self::assertTrue($stream->isReadable());
        } finally {
            @unlink($tmpFile);
        }
    }
}
