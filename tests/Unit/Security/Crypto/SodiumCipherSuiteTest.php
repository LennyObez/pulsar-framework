<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Exception\SecurityException;

use function random_bytes;
use function strlen;

#[CoversClass(SodiumCipherSuite::class)]
final class SodiumCipherSuiteTest extends TestCase
{
    private SodiumCipherSuite $suite;
    private string $key;

    protected function setUp(): void
    {
        $this->suite = new SodiumCipherSuite();
        $this->key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    #[Test]
    public function nameReturnsSodium(): void
    {
        self::assertSame('sodium', $this->suite->name());
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

        self::assertSame(0x01, ord($ciphertext[0]));
    }

    #[Test]
    public function decryptFailsWithWrongKey(): void
    {
        $ciphertext = $this->suite->encrypt('secret', $this->key);
        $wrongKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

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
    public function decryptFailsWithMissingAad(): void
    {
        $ciphertext = $this->suite->encrypt('secret', $this->key, 'some-aad');

        // Decrypting AEAD ciphertext without AAD should fail because secretbox
        // format differs from AEAD format — the nonce/mac layout will mismatch
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

        $this->suite->decrypt(chr(0x01) . 'short', $this->key);
    }

    #[Test]
    public function decryptFailsForWrongVersionByte(): void
    {
        $ciphertext = $this->suite->encrypt('test', $this->key);
        // Change version byte to 0x02 (AES-GCM)
        $ciphertext[0] = chr(0x02);

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
    public function hmacProducesDeterministicOutput(): void
    {
        $hash1 = $this->suite->hmac('hello', $this->key);
        $hash2 = $this->suite->hmac('hello', $this->key);

        self::assertSame($hash1, $hash2);
        self::assertSame(SODIUM_CRYPTO_GENERICHASH_BYTES, strlen($hash1));
    }

    #[Test]
    public function hmacHexProducesDeterministicOutput(): void
    {
        $hex1 = $this->suite->hmacHex('hello', $this->key);
        $hex2 = $this->suite->hmacHex('hello', $this->key);

        self::assertSame($hex1, $hex2);
        self::assertSame(SODIUM_CRYPTO_GENERICHASH_BYTES * 2, strlen($hex1));
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
        $key2 = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

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
        $wrongMac = random_bytes(SODIUM_CRYPTO_GENERICHASH_BYTES);

        self::assertFalse($this->suite->verifyHmac('test message', $wrongMac, $this->key));
    }

    #[Test]
    public function verifyHmacReturnsFalseForTamperedMessage(): void
    {
        $mac = $this->suite->hmac('original', $this->key);

        self::assertFalse($this->suite->verifyHmac('tampered', $mac, $this->key));
    }
}
