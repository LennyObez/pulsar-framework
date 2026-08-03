<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\AesGcmCipherSuite;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Exception\SecurityException;

use function chr;
use function ord;
use function random_bytes;
use function strlen;

#[CoversClass(AesGcmCipherSuite::class)]
final class AesGcmCipherSuiteTest extends TestCase
{
    private AesGcmCipherSuite $suite;
    private string $key;

    protected function setUp(): void
    {
        $this->suite = new AesGcmCipherSuite();
        $this->key = random_bytes(32); // AES-256 requires 32-byte key
    }

    #[Test]
    public function nameReturnsAesGcm(): void
    {
        self::assertSame('aes-gcm', $this->suite->name());
    }

    #[Test]
    public function encryptDecryptRoundTripWithoutAad(): void
    {
        $plaintext = 'The quick brown fox jumps over the lazy dog';

        $ciphertext = $this->suite->encrypt($plaintext, $this->key);
        $decrypted = $this->suite->decrypt($ciphertext, $this->key);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function encryptDecryptRoundTripWithAad(): void
    {
        $plaintext = 'AEAD protected message';
        $aad = 'context:user-id:42';

        $ciphertext = $this->suite->encrypt($plaintext, $this->key, $aad);
        $decrypted = $this->suite->decrypt($ciphertext, $this->key, $aad);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function encryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = 'same message';

        $ct1 = $this->suite->encrypt($plaintext, $this->key);
        $ct2 = $this->suite->encrypt($plaintext, $this->key);

        self::assertNotSame($ct1, $ct2);
    }

    #[Test]
    public function ciphertextStartsWithVersionByte(): void
    {
        $ciphertext = $this->suite->encrypt('test', $this->key);

        self::assertSame(0x02, ord($ciphertext[0]));
    }

    #[Test]
    public function ciphertextHasCorrectMinimumLength(): void
    {
        $ciphertext = $this->suite->encrypt('', $this->key);

        // version (1) + nonce (12) + tag (16) + empty ciphertext (0)
        self::assertGreaterThanOrEqual(29, strlen($ciphertext));
    }

    #[Test]
    public function decryptFailsWithWrongKey(): void
    {
        $ciphertext = $this->suite->encrypt('secret', $this->key);
        $wrongKey = random_bytes(32);

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($ciphertext, $wrongKey);
    }

    #[Test]
    public function decryptFailsWithWrongAad(): void
    {
        $ciphertext = $this->suite->encrypt('secret', $this->key, 'correct-aad');

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($ciphertext, $this->key, 'wrong-aad');
    }

    #[Test]
    public function decryptFailsWithMissingAadWhenEncryptedWithAad(): void
    {
        $ciphertext = $this->suite->encrypt('secret', $this->key, 'some-aad');

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($ciphertext, $this->key);
    }

    #[Test]
    public function decryptFailsForTamperedCiphertext(): void
    {
        $ciphertext = $this->suite->encrypt('sensitive', $this->key);

        // Tamper with the last byte
        $tampered = $ciphertext;
        $tampered[strlen($tampered) - 1] = chr((ord($tampered[strlen($tampered) - 1]) ^ 0xFF) & 0xFF);

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($tampered, $this->key);
    }

    #[Test]
    public function decryptFailsForTruncatedCiphertext(): void
    {
        $this->expectException(SecurityException::class);

        $this->suite->decrypt(chr(0x02) . 'short', $this->key);
    }

    #[Test]
    public function decryptFailsForWrongVersionByte(): void
    {
        $ciphertext = $this->suite->encrypt('test', $this->key);
        // Change version byte to 0x01 (Sodium)
        $ciphertext[0] = chr(0x01);

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($ciphertext, $this->key);
    }

    #[Test]
    public function decryptFailsForEmptyInput(): void
    {
        $this->expectException(SecurityException::class);

        $this->suite->decrypt('', $this->key);
    }

    #[Test]
    public function encryptHandlesEmptyString(): void
    {
        $ciphertext = $this->suite->encrypt('', $this->key);
        $decrypted = $this->suite->decrypt($ciphertext, $this->key);

        self::assertSame('', $decrypted);
    }

    #[Test]
    public function encryptRejectsWrongKeyLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('32-byte key');

        $this->suite->encrypt('test', random_bytes(16));
    }

    #[Test]
    public function decryptRejectsWrongKeyLength(): void
    {
        $ciphertext = $this->suite->encrypt('test', $this->key);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('32-byte key');

        $this->suite->decrypt($ciphertext, random_bytes(16));
    }

    #[Test]
    public function hmacProducesDeterministicOutput(): void
    {
        $hash1 = $this->suite->hmac('hello', $this->key);
        $hash2 = $this->suite->hmac('hello', $this->key);

        self::assertSame($hash1, $hash2);
        self::assertSame(32, strlen($hash1)); // SHA-256 = 32 bytes
    }

    #[Test]
    public function hmacHexProducesDeterministicOutput(): void
    {
        $hex1 = $this->suite->hmacHex('hello', $this->key);
        $hex2 = $this->suite->hmacHex('hello', $this->key);

        self::assertSame($hex1, $hex2);
        self::assertSame(64, strlen($hex1)); // SHA-256 hex = 64 chars
    }

    #[Test]
    public function hmacDiffersForDifferentMessages(): void
    {
        $hash1 = $this->suite->hmac('hello', $this->key);
        $hash2 = $this->suite->hmac('world', $this->key);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function hmacDiffersForDifferentKeys(): void
    {
        $key2 = random_bytes(32);

        $hash1 = $this->suite->hmac('hello', $this->key);
        $hash2 = $this->suite->hmac('hello', $key2);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function verifyHmacReturnsTrueForValidMac(): void
    {
        $mac = $this->suite->hmac('test message', $this->key);

        self::assertTrue($this->suite->verifyHmac('test message', $mac, $this->key));
    }

    #[Test]
    public function verifyHmacReturnsFalseForInvalidMac(): void
    {
        $wrongMac = random_bytes(32);

        self::assertFalse($this->suite->verifyHmac('test message', $wrongMac, $this->key));
    }

    #[Test]
    public function verifyHmacReturnsFalseForTamperedMessage(): void
    {
        $mac = $this->suite->hmac('original', $this->key);

        self::assertFalse($this->suite->verifyHmac('tampered', $mac, $this->key));
    }

    #[Test]
    public function crossSuiteDetectionSodiumCiphertextCannotBeDecryptedByAesGcm(): void
    {
        $sodiumSuite = new SodiumCipherSuite();
        // Use a 32-byte key compatible with both suites
        $sharedKey = random_bytes(32);

        $sodiumCiphertext = $sodiumSuite->encrypt('secret', $sharedKey);

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($sodiumCiphertext, $sharedKey);
    }

    #[Test]
    public function crossSuiteDetectionAesGcmCiphertextCannotBeDecryptedBySodium(): void
    {
        $sodiumSuite = new SodiumCipherSuite();
        $sharedKey = random_bytes(32);

        $aesGcmCiphertext = $this->suite->encrypt('secret', $sharedKey);

        $this->expectException(SecurityException::class);

        $sodiumSuite->decrypt($aesGcmCiphertext, $sharedKey);
    }
}
