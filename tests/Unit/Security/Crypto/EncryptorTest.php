<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use function chr;
use function ord;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;

use function strlen;

#[CoversClass(Encryptor::class)]
final class EncryptorTest extends TestCase
{
    private Encryptor $encryptor;

    protected function setUp(): void
    {
        $hex = sodium_bin2hex(random_bytes(32));
        $masterKey = MasterKey::fromHex($hex);
        $this->encryptor = new Encryptor($masterKey);
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
        $otherEncryptor = new Encryptor($otherKey);

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
    }
}
