<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Messaging\Encryption\MessageEncryptor;
use SodiumException;

use function chr;
use function ord;
use function strlen;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

#[CoversClass(MessageEncryptor::class)]
final class MessageEncryptorTest extends TestCase
{
    private MessageEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new MessageEncryptor();
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $key = $this->encryptor->generateKey();
        $plaintext = 'Hello, this is a secret message!';

        $result = $this->encryptor->encrypt($plaintext, $key);

        self::assertArrayHasKey('ciphertext', $result);
        self::assertArrayHasKey('nonce', $result);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($result['nonce']));
        self::assertNotSame($plaintext, $result['ciphertext']);

        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $result['nonce'], $key);

        self::assertSame($plaintext, $decrypted);
    }

    public function testDecryptWithWrongKeyReturnsFalse(): void
    {
        $key1 = $this->encryptor->generateKey();
        $key2 = $this->encryptor->generateKey();

        $result = $this->encryptor->encrypt('secret', $key1);
        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $result['nonce'], $key2);

        self::assertFalse($decrypted);
    }

    public function testDecryptWithWrongNonceReturnsFalse(): void
    {
        $key = $this->encryptor->generateKey();
        $result = $this->encryptor->encrypt('secret', $key);

        $wrongNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $wrongNonce, $key);

        self::assertFalse($decrypted);
    }

    public function testDecryptTamperedCiphertextReturnsFalse(): void
    {
        $key = $this->encryptor->generateKey();
        $result = $this->encryptor->encrypt('secret message', $key);

        // Tamper with ciphertext
        $tampered = $result['ciphertext'];
        $tampered[0] = chr(ord($tampered[0]) ^ 0xFF);

        $decrypted = $this->encryptor->decrypt($tampered, $result['nonce'], $key);

        self::assertFalse($decrypted);
    }

    public function testGenerateKeyProducesCorrectLength(): void
    {
        $key = $this->encryptor->generateKey();

        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
    }

    public function testGenerateKeyProducesUniqueKeys(): void
    {
        $key1 = $this->encryptor->generateKey();
        $key2 = $this->encryptor->generateKey();

        self::assertNotSame($key1, $key2);
    }

    public function testEncryptWithInvalidKeyLengthThrows(): void
    {
        $this->expectException(SodiumException::class);

        $this->encryptor->encrypt('test', 'too-short');
    }

    public function testEmptyPlaintextEncryption(): void
    {
        $key = $this->encryptor->generateKey();
        $result = $this->encryptor->encrypt('', $key);

        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $result['nonce'], $key);

        self::assertSame('', $decrypted);
    }

    public function testLargePlaintextEncryption(): void
    {
        $key = $this->encryptor->generateKey();
        $plaintext = str_repeat('A', 65536);

        $result = $this->encryptor->encrypt($plaintext, $key);
        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $result['nonce'], $key);

        self::assertSame($plaintext, $decrypted);
    }

    public function testUniqueNoncePerEncryption(): void
    {
        $key = $this->encryptor->generateKey();

        $result1 = $this->encryptor->encrypt('same message', $key);
        $result2 = $this->encryptor->encrypt('same message', $key);

        // Nonces must differ (random)
        self::assertNotSame($result1['nonce'], $result2['nonce']);
        // Same plaintext + different nonce = different ciphertext
        self::assertNotSame($result1['ciphertext'], $result2['ciphertext']);
    }
}
