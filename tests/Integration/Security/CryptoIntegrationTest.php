<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use SodiumException;

#[CoversClass(MasterKey::class)]
#[CoversClass(Encryptor::class)]
#[CoversClass(Hmac::class)]
final class CryptoIntegrationTest extends TestCase
{
    // 32-byte (64-char hex) test keys
    private const string TEST_KEY_HEX = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    private const string PREV_KEY_HEX = 'cafecafecafecafecafecafecafecafecafecafecafecafecafecafecafecafe';

    // -------------------------------------------------------------------------
    // MasterKey
    // -------------------------------------------------------------------------

    #[Test]
    public function masterKeyFromHexSucceedsWithValidKey(): void
    {
        $key = MasterKey::fromHex(self::TEST_KEY_HEX);

        self::assertFalse($key->hasPreviousKey());
    }

    #[Test]
    public function masterKeyFromHexWithPreviousKey(): void
    {
        $key = MasterKey::fromHex(self::TEST_KEY_HEX, self::PREV_KEY_HEX);

        self::assertTrue($key->hasPreviousKey());
    }

    #[Test]
    public function masterKeyFromHexThrowsOnInvalidLength(): void
    {
        $this->expectException(SecurityException::class);
        (void) MasterKey::fromHex('deadbeef'); // too short
    }

    #[Test]
    public function masterKeyDerivesSubKey(): void
    {
        $key = MasterKey::fromHex(self::TEST_KEY_HEX);

        $subKey = $key->deriveSubKey(1, 'encrypt_');

        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($subKey));
    }

    #[Test]
    public function masterKeyDifferentSubKeyIdsDifferentKeys(): void
    {
        $key = MasterKey::fromHex(self::TEST_KEY_HEX);

        $sub1 = $key->deriveSubKeyHex(1, 'encrypt_');
        $sub2 = $key->deriveSubKeyHex(2, 'encrypt_');

        self::assertNotSame($sub1, $sub2);
    }

    #[Test]
    public function masterKeyDifferentContextsDifferentKeys(): void
    {
        $key = MasterKey::fromHex(self::TEST_KEY_HEX);

        $subA = $key->deriveSubKeyHex(1, 'encrypt_');
        $subB = $key->deriveSubKeyHex(1, 'audit___');

        self::assertNotSame($subA, $subB);
    }

    #[Test]
    public function masterKeyIdIsConsistentForSameKey(): void
    {
        $key1 = MasterKey::fromHex(self::TEST_KEY_HEX);
        $key2 = MasterKey::fromHex(self::TEST_KEY_HEX);

        $id1 = $key1->keyId(1, 'encrypt_');
        $id2 = $key2->keyId(1, 'encrypt_');

        self::assertSame($id1, $id2);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id1);
    }

    #[Test]
    public function masterKeyThrowsOnInvalidContextLength(): void
    {
        $key = MasterKey::fromHex(self::TEST_KEY_HEX);

        $this->expectException(InvalidArgumentException::class);
        $key->deriveSubKey(1, 'too-short'); // 9 bytes, not 8
    }

    // -------------------------------------------------------------------------
    // Encryptor
    // -------------------------------------------------------------------------

    #[Test]
    public function encryptorEncryptAndDecryptRoundtrip(): void
    {
        $masterKey = MasterKey::fromHex(self::TEST_KEY_HEX);
        $encryptor = Encryptor::fromMasterKey($masterKey);

        $plaintext = 'sensitive data: account-42';
        $ciphertext = $encryptor->encrypt($plaintext);

        self::assertNotSame($plaintext, $ciphertext);
        self::assertSame($plaintext, $encryptor->decrypt($ciphertext));
    }

    #[Test]
    public function encryptorProducesDifferentCiphertextEachTime(): void
    {
        $masterKey = MasterKey::fromHex(self::TEST_KEY_HEX);
        $encryptor = Encryptor::fromMasterKey($masterKey);

        $ct1 = $encryptor->encrypt('same plaintext');
        $ct2 = $encryptor->encrypt('same plaintext');

        self::assertNotSame($ct1, $ct2); // nonce randomness
    }

    #[Test]
    public function encryptorDecryptFailsOnTamperedData(): void
    {
        $masterKey = MasterKey::fromHex(self::TEST_KEY_HEX);
        $encryptor = Encryptor::fromMasterKey($masterKey);

        $ciphertext = $encryptor->encrypt('original');
        // Flip last byte in base64 to tamper
        $tampered = substr($ciphertext, 0, -1) . ($ciphertext[-1] === 'A' ? 'B' : 'A');

        $this->expectException(SecurityException::class);
        $encryptor->decrypt($tampered);
    }

    #[Test]
    public function encryptorDecryptFailsOnGarbageInput(): void
    {
        $masterKey = MasterKey::fromHex(self::TEST_KEY_HEX);
        $encryptor = Encryptor::fromMasterKey($masterKey);

        $this->expectException(SecurityException::class);
        $encryptor->decrypt('not-valid-base64-or-ciphertext!!!');
    }

    #[Test]
    public function encryptorKeyRotationFallback(): void
    {
        // Encrypt with the "old" key (previousKey)
        $oldMasterKey = MasterKey::fromHex(self::PREV_KEY_HEX);
        $oldEncryptor = Encryptor::fromMasterKey($oldMasterKey);
        $ciphertext = $oldEncryptor->encrypt('data encrypted with old key');

        // Decrypt with new key that has old key as previous (rotation scenario)
        $newMasterKey = MasterKey::fromHex(self::TEST_KEY_HEX, self::PREV_KEY_HEX);
        $newEncryptor = Encryptor::fromMasterKey($newMasterKey);

        $plaintext = $newEncryptor->decrypt($ciphertext);

        self::assertSame('data encrypted with old key', $plaintext);
    }

    #[Test]
    public function encryptorFromDerivedKeyUsesSpecificSubKey(): void
    {
        $masterKey = MasterKey::fromHex(self::TEST_KEY_HEX);
        $encryptor = Encryptor::fromDerivedKey($masterKey, 10, 'preview_');

        $plaintext = 'preview token';
        $decrypted = $encryptor->decrypt($encryptor->encrypt($plaintext));

        self::assertSame($plaintext, $decrypted);
    }

    // -------------------------------------------------------------------------
    // Hmac
    // -------------------------------------------------------------------------

    #[Test]
    public function hmacComputeHexProducesConsistentResult(): void
    {
        $key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN);
        $message = 'audit log entry 42';

        $hash1 = Hmac::computeHex($message, $key);
        $hash2 = Hmac::computeHex($message, $key);

        self::assertSame($hash1, $hash2);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash1);
    }

    #[Test]
    public function hmacVerifyHexSucceedsForValidSignature(): void
    {
        $key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN);
        $message = 'important message';

        $hash = Hmac::computeHex($message, $key);

        self::assertTrue(Hmac::verifyHex($message, $hash, $key));
    }

    #[Test]
    public function hmacVerifyHexFailsForTamperedMessage(): void
    {
        $key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN);
        $hash = Hmac::computeHex('original', $key);

        self::assertFalse(Hmac::verifyHex('tampered', $hash, $key));
    }

    #[Test]
    public function hmacVerifyHexFailsForWrongKey(): void
    {
        $key1 = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN);
        $key2 = str_repeat('x', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN);
        $message = 'message';

        $hash = Hmac::computeHex($message, $key1);

        self::assertFalse(Hmac::verifyHex($message, $hash, $key2));
    }

    #[Test]
    public function hmacRawBytesRoundtrip(): void
    {
        $key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN);
        $message = 'raw bytes test';

        $raw = Hmac::compute($message, $key);

        self::assertTrue(Hmac::verify($message, $raw, $key));
        self::assertSame(SODIUM_CRYPTO_GENERICHASH_BYTES, strlen($raw));
    }

    #[Test]
    public function hmacThrowsOnTooShortKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (void) Hmac::computeHex('message', 'short'); // less than MIN_KEY_LENGTH
    }
}
