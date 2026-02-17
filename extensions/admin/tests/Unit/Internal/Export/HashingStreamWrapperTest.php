<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Export\HashingStreamWrapper;

use function strlen;

final class HashingStreamWrapperTest extends TestCase
{
    #[Test]
    public function write_and_read_contents(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('Hello');
        $stream->write(' World');

        self::assertSame('Hello World', $stream->contents());
    }

    #[Test]
    public function evidence_hash_computed_on_close(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('test data');

        self::assertSame('', $stream->evidenceHash);
        self::assertFalse($stream->closed);

        $stream->close();

        self::assertTrue($stream->closed);
        self::assertNotEmpty($stream->evidenceHash);
        self::assertSame(64, strlen($stream->evidenceHash));
    }

    #[Test]
    public function evidence_hash_is_deterministic(): void
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
    public function different_content_different_hash(): void
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
    public function double_close_is_idempotent(): void
    {
        $stream = new HashingStreamWrapper();
        $stream->write('test');
        $stream->close();
        $hash1 = $stream->evidenceHash;

        $stream->close();
        $hash2 = $stream->evidenceHash;

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function empty_stream(): void
    {
        $stream = new HashingStreamWrapper();
        self::assertSame('', $stream->contents());

        $stream->close();
        self::assertNotEmpty($stream->evidenceHash);
    }

    #[Test]
    public function hash_matches_sha256(): void
    {
        $data = 'evidence hash verification';
        $stream = new HashingStreamWrapper();
        $stream->write($data);
        $stream->close();

        self::assertSame(hash('sha256', $data), $stream->evidenceHash);
    }
}
