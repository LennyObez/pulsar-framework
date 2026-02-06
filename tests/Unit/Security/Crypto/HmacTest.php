<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Hmac;

use function strlen;

#[CoversClass(Hmac::class)]
final class HmacTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        // 32-byte key (meets minimum BLAKE2b requirement)
        $this->key = random_bytes(32);
    }

    #[Test]
    public function computeHexReturnsDeterministicHash(): void
    {
        $hash1 = Hmac::computeHex('hello', $this->key);
        $hash2 = Hmac::computeHex('hello', $this->key);

        self::assertSame($hash1, $hash2);
        self::assertSame(64, strlen($hash1)); // 32 bytes = 64 hex chars
    }

    #[Test]
    public function differentMessagesDifferentHashes(): void
    {
        $hash1 = Hmac::computeHex('hello', $this->key);
        $hash2 = Hmac::computeHex('world', $this->key);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function differentKeysDifferentHashes(): void
    {
        $key2 = random_bytes(32);

        $hash1 = Hmac::computeHex('hello', $this->key);
        $hash2 = Hmac::computeHex('hello', $key2);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function verifyHexReturnsTrueForValidHmac(): void
    {
        $hash = Hmac::computeHex('test message', $this->key);

        self::assertTrue(Hmac::verifyHex('test message', $hash, $this->key));
    }

    #[Test]
    public function verifyHexReturnsFalseForInvalidHmac(): void
    {
        self::assertFalse(Hmac::verifyHex('test message', str_repeat('a', 64), $this->key));
    }

    #[Test]
    public function verifyHexReturnsFalseForTamperedMessage(): void
    {
        $hash = Hmac::computeHex('original', $this->key);

        self::assertFalse(Hmac::verifyHex('tampered', $hash, $this->key));
    }

    #[Test]
    public function computeReturnsRawBytes(): void
    {
        $raw = Hmac::compute('hello', $this->key);

        self::assertSame(32, strlen($raw)); // BLAKE2b-256 = 32 bytes
    }

    #[Test]
    public function verifyReturnsTrueForValidRawHmac(): void
    {
        $raw = Hmac::compute('test', $this->key);

        self::assertTrue(Hmac::verify('test', $raw, $this->key));
    }

    #[Test]
    public function verifyReturnsFalseForInvalidRawHmac(): void
    {
        self::assertFalse(Hmac::verify('test', random_bytes(32), $this->key));
    }

    #[Test]
    public function throwsForKeyTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HMAC key must be at least');

        $_ = Hmac::computeHex('message', 'short');
    }
}
