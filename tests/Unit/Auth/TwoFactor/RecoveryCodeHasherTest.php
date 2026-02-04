<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\RecoveryCodeHasher;

use function strlen;

#[CoversClass(RecoveryCodeHasher::class)]
final class RecoveryCodeHasherTest extends TestCase
{
    private RecoveryCodeHasher $hasher;

    protected function setUp(): void
    {
        // Use a fixed 32-byte key for deterministic testing
        $this->hasher = new RecoveryCodeHasher(str_repeat('k', 32));
    }

    #[Test]
    public function hashProducesDeterministicOutput(): void
    {
        $hash1 = $this->hasher->hash('ABCD-1234-EF56-7890');
        $hash2 = $this->hasher->hash('ABCD-1234-EF56-7890');

        self::assertSame($hash1, $hash2);
        self::assertSame(64, strlen($hash1)); // 32 bytes = 64 hex chars
    }

    #[Test]
    public function hashAllReturnsCorrectCount(): void
    {
        $codes = ['ABCD-1234-EF56-7890', 'DEAD-BEEF-CAFE-BABE'];
        $hashes = $this->hasher->hashAll($codes);

        self::assertCount(2, $hashes);
        self::assertSame($this->hasher->hash($codes[0]), $hashes[0]);
        self::assertSame($this->hasher->hash($codes[1]), $hashes[1]);
    }

    #[Test]
    public function verifyMatchesCorrectCode(): void
    {
        $codes = ['ABCD-1234-EF56-7890', 'DEAD-BEEF-CAFE-BABE'];
        $hashes = $this->hasher->hashAll($codes);

        self::assertSame(0, $this->hasher->verify('ABCD-1234-EF56-7890', $hashes));
        self::assertSame(1, $this->hasher->verify('DEAD-BEEF-CAFE-BABE', $hashes));
    }

    #[Test]
    public function verifyReturnsNegativeOneForMiss(): void
    {
        $hashes = $this->hasher->hashAll(['ABCD-1234-EF56-7890']);

        self::assertSame(-1, $this->hasher->verify('FFFF-FFFF-FFFF-FFFF', $hashes));
    }

    #[Test]
    public function verifyIsCaseInsensitive(): void
    {
        $hashes = $this->hasher->hashAll(['ABCD-1234-EF56-7890']);

        self::assertSame(0, $this->hasher->verify('abcd-1234-ef56-7890', $hashes));
    }

    #[Test]
    public function canonicalizationStripsDelimiters(): void
    {
        $hash1 = $this->hasher->hash('ABCD-1234-EF56-7890');
        $hash2 = $this->hasher->hash('ABCD 1234 EF56 7890');
        $hash3 = $this->hasher->hash('ABCD1234EF567890');

        self::assertSame($hash1, $hash2);
        self::assertSame($hash1, $hash3);
    }

    #[Test]
    public function canonicalizeStaticMethod(): void
    {
        self::assertSame('ABCD1234EF567890', RecoveryCodeHasher::canonicalize('abcd-1234-ef56-7890'));
        self::assertSame('ABCD1234EF567890', RecoveryCodeHasher::canonicalize('ABCD 1234 EF56 7890'));
    }
}
