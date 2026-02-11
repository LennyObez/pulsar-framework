<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Exception\SecurityException;

use function chr;
use function ord;
use function random_bytes;
use function strlen;

/**
 * Tests for SodiumCipherSuite AEAD paths not covered in the main test.
 */
#[CoversClass(SodiumCipherSuite::class)]
final class SodiumCipherSuiteAeadTest extends TestCase
{
    private SodiumCipherSuite $suite;
    private string $key;

    protected function setUp(): void
    {
        $this->suite = new SodiumCipherSuite();
        $this->key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    #[Test]
    public function decryptAeadFailsForTruncatedCiphertext(): void
    {
        $this->expectException(SecurityException::class);

        // Version byte + very short data — too short for AEAD nonce + tag
        $this->suite->decrypt(chr(0x01) . 'short', $this->key, 'some-aad');
    }

    #[Test]
    public function decryptAeadFailsForTamperedCiphertext(): void
    {
        $ciphertext = $this->suite->encrypt('secret aead data', $this->key, 'my-aad');

        // Tamper with the last byte
        $last = $ciphertext[strlen($ciphertext) - 1];
        $ciphertext[strlen($ciphertext) - 1] = chr((ord($last) ^ 0xFF) & 0xFF);

        $this->expectException(SecurityException::class);

        $this->suite->decrypt($ciphertext, $this->key, 'my-aad');
    }

    #[Test]
    public function aeadEncryptDecryptWithEmptyPlaintext(): void
    {
        $ciphertext = $this->suite->encrypt('', $this->key, 'aad-context');
        $decrypted = $this->suite->decrypt($ciphertext, $this->key, 'aad-context');

        self::assertSame('', $decrypted);
    }

    #[Test]
    public function aeadEncryptProducesDifferentCiphertextEachTime(): void
    {
        $ct1 = $this->suite->encrypt('same', $this->key, 'aad');
        $ct2 = $this->suite->encrypt('same', $this->key, 'aad');

        self::assertNotSame($ct1, $ct2);
    }
}
