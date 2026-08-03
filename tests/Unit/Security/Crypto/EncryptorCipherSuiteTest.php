<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Exception\SecurityException;

use function random_bytes;
use function sodium_bin2hex;

/**
 * Tests for Encryptor paths that use CipherSuiteInterface,
 * fromDerivedKey, withDerivedKey, cipherSuite getter, and __unserialize.
 */
#[CoversClass(Encryptor::class)]
final class EncryptorCipherSuiteTest extends TestCase
{
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    #[Test]
    public function encryptDecryptWithCipherSuite(): void
    {
        $suite = new SodiumCipherSuite();
        $encryptor = Encryptor::fromMasterKey($this->masterKey, $suite);

        $plaintext = 'cipher suite round trip';
        $ciphertext = $encryptor->encrypt($plaintext);
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function cipherSuiteGetterReturnsConfiguredSuite(): void
    {
        $suite = new SodiumCipherSuite();
        $encryptor = Encryptor::fromMasterKey($this->masterKey, $suite);

        self::assertSame($suite, $encryptor->cipherSuite());
    }

    #[Test]
    public function cipherSuiteGetterReturnsNullWhenNotConfigured(): void
    {
        $encryptor = Encryptor::fromMasterKey($this->masterKey);

        self::assertNull($encryptor->cipherSuite());
    }

    #[Test]
    public function fromDerivedKeyCreatesEncryptor(): void
    {
        $encryptor = Encryptor::fromDerivedKey($this->masterKey, 5, 'custom__');

        $ciphertext = $encryptor->encrypt('derived key data');
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame('derived key data', $decrypted);
    }

    #[Test]
    public function fromDerivedKeyWithCipherSuite(): void
    {
        $suite = new SodiumCipherSuite();
        $encryptor = Encryptor::fromDerivedKey($this->masterKey, 5, 'custom__', $suite);

        $ciphertext = $encryptor->encrypt('derived with suite');
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame('derived with suite', $decrypted);
        self::assertSame($suite, $encryptor->cipherSuite());
    }

    #[Test]
    public function fromDerivedKeyWithRotationSupport(): void
    {
        $oldHex = sodium_bin2hex(random_bytes(32));
        $newHex = sodium_bin2hex(random_bytes(32));
        $rotatedMaster = MasterKey::fromHex($newHex, $oldHex);

        $encryptor = Encryptor::fromDerivedKey($rotatedMaster, 5, 'custom__');

        $debug = $encryptor->__debugInfo();
        self::assertSame('[REDACTED]', $debug['previousKey']);
    }

    #[Test]
    public function withDerivedKeyCreatesNewEncryptor(): void
    {
        $encryptor = Encryptor::fromMasterKey($this->masterKey);
        $derived = $encryptor->withDerivedKey($this->masterKey, 7, 'otherctx');

        $ciphertext = $derived->encrypt('with derived');
        $decrypted = $derived->decrypt($ciphertext);

        self::assertSame('with derived', $decrypted);
    }

    #[Test]
    public function withDerivedKeyPreservesCipherSuite(): void
    {
        $suite = new SodiumCipherSuite();
        $encryptor = Encryptor::fromMasterKey($this->masterKey, $suite);
        $derived = $encryptor->withDerivedKey($this->masterKey, 7, 'otherctx');

        self::assertSame($suite, $derived->cipherSuite());
    }

    #[Test]
    public function withDerivedKeySupportsRotation(): void
    {
        $oldHex = sodium_bin2hex(random_bytes(32));
        $newHex = sodium_bin2hex(random_bytes(32));
        $rotatedMaster = MasterKey::fromHex($newHex, $oldHex);

        $encryptor = Encryptor::fromMasterKey($rotatedMaster);
        $derived = $encryptor->withDerivedKey($rotatedMaster, 7, 'otherctx');

        $debug = $derived->__debugInfo();
        self::assertSame('[REDACTED]', $debug['previousKey']);
    }

    #[Test]
    public function unserializeThrows(): void
    {
        $encryptor = Encryptor::fromMasterKey($this->masterKey);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization');

        $encryptor->__unserialize([]);
    }

    #[Test]
    public function cipherSuiteDecryptionWithKeyRotationFallback(): void
    {
        $suite = new SodiumCipherSuite();

        // Encrypt with old key
        $oldHex = sodium_bin2hex(random_bytes(32));
        $oldMaster = MasterKey::fromHex($oldHex);
        $oldEncryptor = Encryptor::fromMasterKey($oldMaster, $suite);

        $ciphertext = $oldEncryptor->encrypt('old key data');

        // Create rotated encryptor with new key + old as previous
        $newHex = sodium_bin2hex(random_bytes(32));
        $rotatedMaster = MasterKey::fromHex($newHex, $oldHex);
        $rotatedEncryptor = Encryptor::fromMasterKey($rotatedMaster, $suite);

        // Should decrypt using fallback to previous key
        $decrypted = $rotatedEncryptor->decrypt($ciphertext);
        self::assertSame('old key data', $decrypted);
    }

    #[Test]
    public function cipherSuiteDecryptionFailsForBothKeys(): void
    {
        $suite = new SodiumCipherSuite();

        $key1Hex = sodium_bin2hex(random_bytes(32));
        $key2Hex = sodium_bin2hex(random_bytes(32));
        $key3Hex = sodium_bin2hex(random_bytes(32));

        $master1 = MasterKey::fromHex($key1Hex);
        $encryptor1 = Encryptor::fromMasterKey($master1, $suite);
        $ciphertext = $encryptor1->encrypt('data');

        // Encryptor with completely different keys
        $master23 = MasterKey::fromHex($key2Hex, $key3Hex);
        $encryptor23 = Encryptor::fromMasterKey($master23, $suite);

        $this->expectException(SecurityException::class);

        $encryptor23->decrypt($ciphertext);
    }

    #[Test]
    public function cipherSuiteDecryptionFailsWithoutPreviousKey(): void
    {
        $suite = new SodiumCipherSuite();

        $key1Hex = sodium_bin2hex(random_bytes(32));
        $key2Hex = sodium_bin2hex(random_bytes(32));

        $master1 = MasterKey::fromHex($key1Hex);
        $encryptor1 = Encryptor::fromMasterKey($master1, $suite);
        $ciphertext = $encryptor1->encrypt('data');

        // Encryptor with a completely different key (no previous)
        $master2 = MasterKey::fromHex($key2Hex);
        $encryptor2 = Encryptor::fromMasterKey($master2, $suite);

        $this->expectException(SecurityException::class);

        $encryptor2->decrypt($ciphertext);
    }

    #[Test]
    public function debugInfoShowsCipherSuiteName(): void
    {
        $suite = new SodiumCipherSuite();
        $encryptor = Encryptor::fromMasterKey($this->masterKey, $suite);

        $debug = $encryptor->__debugInfo();

        self::assertSame('sodium', $debug['cipherSuite']);
    }

    #[Test]
    public function debugInfoShowsLegacyWhenNoCipherSuite(): void
    {
        $encryptor = Encryptor::fromMasterKey($this->masterKey);

        $debug = $encryptor->__debugInfo();

        self::assertSame('legacy-sodium', $debug['cipherSuite']);
    }
}
