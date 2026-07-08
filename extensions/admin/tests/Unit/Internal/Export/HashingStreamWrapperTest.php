<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Export\HashingStreamWrapper;

use function hash;
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

        self::assertFalse($stream->closed);
        self::assertSame('', $stream->evidenceHash);

        $stream->close();

        self::assertTrue($stream->closed);
        self::assertNotSame('', $stream->evidenceHash);
    }

    #[Test]
    public function evidenceHashIsSha256(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('test data');
        $stream->close();

        $hash = $stream->evidenceHash;

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

        self::assertSame(hash('sha256', 'hello'), $stream->evidenceHash);
    }

    #[Test]
    public function multipleWritesProduceCorrectHash(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('hello');
        $stream->write(' world');
        $stream->close();

        self::assertSame(hash('sha256', 'hello world'), $stream->evidenceHash);
    }

    #[Test]
    public function evidenceHashIsDeterministic(): void
    {
        $stream1 = new HashingStreamWrapper();
        $stream1->write('deterministic test');
        $stream1->close();

        $stream2 = new HashingStreamWrapper();
        $stream2->write('deterministic test');
        $stream2->close();

        self::assertSame($stream1->evidenceHash, $stream2->evidenceHash);
    }

    #[Test]
    public function differentContentProducesDifferentHash(): void
    {
        $stream1 = new HashingStreamWrapper();
        $stream1->write('content A');
        $stream1->close();

        $stream2 = new HashingStreamWrapper();
        $stream2->write('content B');
        $stream2->close();

        self::assertNotSame($stream1->evidenceHash, $stream2->evidenceHash);
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('data');
        $stream->close();
        $hash1 = $stream->evidenceHash;

        $stream->close();
        $hash2 = $stream->evidenceHash;

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function emptyStreamProducesHash(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->close();

        self::assertSame(hash('sha256', ''), $stream->evidenceHash);
        self::assertSame('', $stream->contents());
    }
}
