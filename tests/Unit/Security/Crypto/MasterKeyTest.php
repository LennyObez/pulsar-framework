<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;

use function strlen;

#[CoversClass(MasterKey::class)]
final class MasterKeyTest extends TestCase
{
    private string $validHex;

    protected function setUp(): void
    {
        // 32-byte key hex-encoded
        $this->validHex = sodium_bin2hex(random_bytes(32));
    }

    #[Test]
    public function fromHexCreatesInstance(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        self::assertInstanceOf(MasterKey::class, $masterKey);
    }

    #[Test]
    public function fromHexThrowsForInvalidLength(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('expected');

        $_ = MasterKey::fromHex(sodium_bin2hex(random_bytes(16))); // 16 bytes, need 32
    }

    #[Test]
    public function deriveSubKeyReturnsDeterministicOutput(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $subKey1 = $masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $masterKey->deriveSubKey(1, 'encrypt_');

        self::assertSame($subKey1, $subKey2);
    }

    #[Test]
    public function differentSubKeyIdsDifferentKeys(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $subKey1 = $masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $masterKey->deriveSubKey(2, 'encrypt_');

        self::assertNotSame($subKey1, $subKey2);
    }

    #[Test]
    public function differentContextsDifferentKeys(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $subKey1 = $masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $masterKey->deriveSubKey(1, 'audit___');

        self::assertNotSame($subKey1, $subKey2);
    }

    #[Test]
    public function deriveSubKeyHexReturnsHexString(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $hex = $masterKey->deriveSubKeyHex(1, 'encrypt_');

        // 32-byte key = 64 hex chars
        self::assertSame(64, strlen($hex));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
    }

    #[Test]
    public function shortContextThrows(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KDF context must be exactly 8 bytes, got 2');

        $masterKey->deriveSubKey(1, 'ab');
    }

    #[Test]
    public function longContextThrows(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KDF context must be exactly 8 bytes, got 27');

        $masterKey->deriveSubKey(1, 'this_is_a_very_long_context');
    }

    #[Test]
    public function debugInfoRedactsKey(): void
    {
        $masterKey = MasterKey::fromHex($this->validHex);

        $debug = $masterKey->__debugInfo();

        self::assertSame('[REDACTED]', $debug['rawKey']);
    }

    #[Test]
    public function fromEnvironmentThrowsWhenMissing(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('PULSAR_MASTER_KEY');

        $_ = MasterKey::fromEnvironment('');
    }

    #[Test]
    public function fromEnvironmentWithExplicitValue(): void
    {
        $masterKey = MasterKey::fromEnvironment($this->validHex);

        self::assertInstanceOf(MasterKey::class, $masterKey);
    }
}
