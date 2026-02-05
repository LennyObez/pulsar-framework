<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;

use function chr;
use function ord;
use function strlen;

#[CoversClass(Encryptor::class)]
final class EncryptorTest extends TestCase
{
    private Encryptor $encryptor;

    protected function setUp(): void
    {
        $hex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex);
        $this->encryptor = Encryptor::fromMasterKey($masterKey);
    }

    #[Test]
    public function encryptAndDecryptRoundTrip(): void
    {
        $plaintext = 'The quick brown fox jumps over the lazy dog';

        $ciphertext = $this->encryptor->encrypt($plaintext);
        $decrypted = $this->encryptor->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function encryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = 'same message';

        $ct1 = $this->encryptor->encrypt($plaintext);
        $ct2 = $this->encryptor->encrypt($plaintext);

        self::assertNotSame($ct1, $ct2);
    }

    #[Test]
    public function decryptFailsForTamperedCiphertext(): void
    {
        $ciphertext = $this->encryptor->encrypt('sensitive');

        // Tamper with the ciphertext
        $decoded = base64_decode($ciphertext, true);
        self::assertIsString($decoded);

        $tampered = $decoded;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('tampered');

        $this->encryptor->decrypt(base64_encode($tampered));
    }

    #[Test]
    public function decryptFailsForInvalidBase64(): void
    {
        $this->expectException(SecurityException::class);

        $this->encryptor->decrypt('not-valid-base64!!!');
    }

    #[Test]
    public function decryptFailsForTruncatedCiphertext(): void
    {
        $this->expectException(SecurityException::class);

        $this->encryptor->decrypt(base64_encode('short'));
    }

    #[Test]
    public function differentMasterKeyCannotDecrypt(): void
    {
        $ciphertext = $this->encryptor->encrypt('secret');

        $otherHex = sodium_bin2hex(random_bytes(32));
        $otherKey = MasterKey::fromHex($otherHex);
        $otherEncryptor = Encryptor::fromMasterKey($otherKey);

        $this->expectException(SecurityException::class);

        $otherEncryptor->decrypt($ciphertext);
    }

    #[Test]
    public function encryptHandlesEmptyString(): void
    {
        $ciphertext = $this->encryptor->encrypt('');
        $decrypted = $this->encryptor->decrypt($ciphertext);

        self::assertSame('', $decrypted);
    }

    #[Test]
    public function encryptHandlesLargePayload(): void
    {
        $plaintext = str_repeat('A', 100_000);

        $ciphertext = $this->encryptor->encrypt($plaintext);
        $decrypted = $this->encryptor->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function debugInfoRedactsKey(): void
    {
        $debug = $this->encryptor->__debugInfo();

        self::assertSame('[REDACTED]', $debug['key']);
        self::assertSame('[NONE]', $debug['previousKey']);
    }

    #[Test]
    public function decryptFallsBackToPreviousKey(): void
    {
        // Encrypt with old key
        $oldHex = sodium_bin2hex(random_bytes(32));
        $oldMaster = MasterKey::fromHex($oldHex);
        $oldEncryptor = Encryptor::fromMasterKey($oldMaster);

        $ciphertext = $oldEncryptor->encrypt('secret data');

        // Create rotated encryptor with new key + old as previous
        $newHex = sodium_bin2hex(random_bytes(32));
        $rotatedMaster = MasterKey::fromHex($newHex, $oldHex);
        $rotatedEncryptor = Encryptor::fromMasterKey($rotatedMaster);

        // Should decrypt using fallback to previous key
        $decrypted = $rotatedEncryptor->decrypt($ciphertext);
        self::assertSame('secret data', $decrypted);
    }

    #[Test]
    public function encryptUsesCurrentKeyNotPrevious(): void
    {
        $oldHex = sodium_bin2hex(random_bytes(32));
        $newHex = sodium_bin2hex(random_bytes(32));
        $rotatedMaster = MasterKey::fromHex($newHex, $oldHex);
        $rotatedEncryptor = Encryptor::fromMasterKey($rotatedMaster);

        $ciphertext = $rotatedEncryptor->encrypt('new data');

        // New key-only encryptor should decrypt (proves encrypt uses current key)
        $newOnlyMaster = MasterKey::fromHex($newHex);
        $newOnlyEncryptor = Encryptor::fromMasterKey($newOnlyMaster);
        self::assertSame('new data', $newOnlyEncryptor->decrypt($ciphertext));
    }

    #[Test]
    public function bothKeysMissingThrowsDecryptionFailed(): void
    {
        $key1Hex = sodium_bin2hex(random_bytes(32));
        $key2Hex = sodium_bin2hex(random_bytes(32));
        $key3Hex = sodium_bin2hex(random_bytes(32));

        $master1 = MasterKey::fromHex($key1Hex);
        $encryptor1 = Encryptor::fromMasterKey($master1);
        $ciphertext = $encryptor1->encrypt('data');

        // Encryptor with completely different keys (current + previous)
        $master23 = MasterKey::fromHex($key2Hex, $key3Hex);
        $encryptor23 = Encryptor::fromMasterKey($master23);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('tampered');

        $encryptor23->decrypt($ciphertext);
    }

    #[Test]
    public function serializationThrows(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Serialization');

        serialize($this->encryptor);
    }

    #[Test]
    public function debugInfoRedactsPreviousKeyWhenPresent(): void
    {
        $hex1 = sodium_bin2hex(random_bytes(32));
        $hex2 = sodium_bin2hex(random_bytes(32));
        $master = MasterKey::fromHex($hex1, $hex2);
        $encryptor = Encryptor::fromMasterKey($master);

        $debug = $encryptor->__debugInfo();

        self::assertSame('[REDACTED]', $debug['key']);
        self::assertSame('[REDACTED]', $debug['previousKey']);
    }
}
