<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\SodiumCipherSuite;

use function random_bytes;
use function strlen;

/**
 * Tests for Hmac cipher suite delegation paths.
 */
#[CoversClass(Hmac::class)]
final class HmacCipherSuiteTest extends TestCase
{
    private SodiumCipherSuite $suite;
    private string $key;

    protected function setUp(): void
    {
        $this->suite = new SodiumCipherSuite();
        $this->key = random_bytes(32);
    }

    #[Test]
    public function computeHexDelegatesToCipherSuite(): void
    {
        $hash = Hmac::computeHex('hello', $this->key, $this->suite);

        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function computeDelegatesToCipherSuite(): void
    {
        $hash = Hmac::compute('hello', $this->key, $this->suite);

        self::assertSame(SODIUM_CRYPTO_GENERICHASH_BYTES, strlen($hash));
    }

    #[Test]
    public function verifyHexWithCipherSuiteReturnsTrue(): void
    {
        $hash = Hmac::computeHex('test message', $this->key, $this->suite);

        self::assertTrue(Hmac::verifyHex('test message', $hash, $this->key, $this->suite));
    }

    #[Test]
    public function verifyHexWithCipherSuiteReturnsFalse(): void
    {
        self::assertFalse(Hmac::verifyHex('test message', str_repeat('a', 64), $this->key, $this->suite));
    }

    #[Test]
    public function verifyWithCipherSuiteReturnsTrue(): void
    {
        $hash = Hmac::compute('test', $this->key, $this->suite);

        self::assertTrue(Hmac::verify('test', $hash, $this->key, $this->suite));
    }

    #[Test]
    public function verifyWithCipherSuiteReturnsFalse(): void
    {
        self::assertFalse(Hmac::verify('test', random_bytes(32), $this->key, $this->suite));
    }
}
