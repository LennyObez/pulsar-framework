<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Export\HashingStreamWrapper;

use function strlen;

#[CoversClass(HashingStreamWrapper::class)]
final class HashingStreamWrapperTest extends TestCase
{
    #[Test]
    public function writeAccumulatesData(): void
    {
        $stream = new HashingStreamWrapper();

        $stream->write('hello');
        $stream->write(' world');

        self::assertSame('hello world', $stream->contents());
    }

    #[Test]
    public function closeFinalizesHash(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('test data');

        self::assertFalse($stream->isClosed());
        self::assertSame('', $stream->evidenceHash());

        $stream->close();

        self::assertTrue($stream->isClosed());
        self::assertNotSame('', $stream->evidenceHash());
    }

    #[Test]
    public function evidenceHashIsSha256(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('test data');
        $stream->close();

        $hash = $stream->evidenceHash();

        // SHA-256 produces a 64-character hex string
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function evidenceHashMatchesExpected(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('hello');
        $stream->close();

        self::assertSame(hash('sha256', 'hello'), $stream->evidenceHash());
    }

    #[Test]
    public function multipleWritesProduceCorrectHash(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('hello');
        $stream->write(' world');
        $stream->close();

        self::assertSame(hash('sha256', 'hello world'), $stream->evidenceHash());
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('data');
        $stream->close();
        $hash1 = $stream->evidenceHash();

        $stream->close();
        $hash2 = $stream->evidenceHash();

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function emptyStreamProducesHash(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->close();

        self::assertSame(hash('sha256', ''), $stream->evidenceHash());
        self::assertSame('', $stream->contents());
    }
}
