<?php

declare(strict_types=1);

namespace Pulsar\Tests\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;

use function count;
use function random_bytes;
use function sodium_bin2hex;
use function strlen;

#[CoversClass(Encryptor::class)]
#[CoversClass(Hmac::class)]
#[CoversClass(MasterKey::class)]
#[Group('property')]
final class CryptoRoundtripTest extends TestCase
{
    private MasterKey $masterKey;

    protected function setUp(): void
    {
        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
    }

    #[Test]
    public function encryptDecryptRoundtripPreservesPlaintext(): void
    {
        $encryptor = Encryptor::fromMasterKey($this->masterKey);

        $plaintexts = [
            '',
            'Hello, World!',
            random_bytes(1),
            random_bytes(16),
            random_bytes(256),
            random_bytes(4096),
            str_repeat("\x00", 100),
            str_repeat("\xFF", 100),
            'Unicode: 日本語 العربية 中文',
            str_repeat('A', 65536),
        ];

        foreach ($plaintexts as $plaintext) {
            $ciphertext = $encryptor->encrypt($plaintext);
            $decrypted = $encryptor->decrypt($ciphertext);

            self::assertSame(
                $plaintext,
                $decrypted,
                'Decrypted text must exactly equal original plaintext (length: ' . strlen($plaintext) . ')',
            );
        }
    }

    #[Test]
    public function hmacVerifyAlwaysAcceptsCorrectSignature(): void
    {
        $key = random_bytes(32);

        $messages = [
            '',
            'short',
            random_bytes(64),
            random_bytes(1024),
            str_repeat('message', 1000),
            "\x00\x01\x02\x03",
        ];

        foreach ($messages as $message) {
            $hex = Hmac::computeHex($message, $key);
            $valid = Hmac::verifyHex($message, $hex, $key);

            self::assertTrue($valid, 'HMAC verification must succeed for correctly signed message');
        }
    }

    #[Test]
    public function hmacVerifyRejectsModifiedMessage(): void
    {
        $key = random_bytes(32);

        $messages = [
            'original message',
            random_bytes(64),
            'test data for integrity',
        ];

        foreach ($messages as $message) {
            $hex = Hmac::computeHex($message, $key);

            // Modify the message slightly
            $modified = $message . 'x';
            $valid = Hmac::verifyHex($modified, $hex, $key);

            self::assertFalse($valid, 'HMAC verification must fail for modified message');
        }
    }

    #[Test]
    public function differentKeysProduceDifferentCiphertext(): void
    {
        $key1Hex = sodium_bin2hex(random_bytes(32));
        $key2Hex = sodium_bin2hex(random_bytes(32));

        $masterKey1 = MasterKey::fromHex($key1Hex);
        $masterKey2 = MasterKey::fromHex($key2Hex);

        $encryptor1 = Encryptor::fromMasterKey($masterKey1);
        $encryptor2 = Encryptor::fromMasterKey($masterKey2);

        $plaintext = 'test data for key separation';

        $ciphertext1 = $encryptor1->encrypt($plaintext);
        $ciphertext2 = $encryptor2->encrypt($plaintext);

        // Ciphertexts should differ (with overwhelming probability)
        self::assertNotSame($ciphertext1, $ciphertext2, 'Different keys must produce different ciphertext');
    }

    #[Test]
    public function sameKeyDifferentNoncesProduceDifferentCiphertext(): void
    {
        $encryptor = Encryptor::fromMasterKey($this->masterKey);
        $plaintext = 'same plaintext encrypted multiple times';

        $ciphertexts = [];
        for ($i = 0; $i < 20; $i++) {
            $ciphertexts[] = $encryptor->encrypt($plaintext);
        }

        // All ciphertexts should be unique (random nonce ensures this)
        $unique = array_unique($ciphertexts);
        self::assertCount(
            count($ciphertexts),
            $unique,
            'Each encryption must produce unique ciphertext due to random nonce',
        );
    }

    #[Test]
    public function derivedSubkeysAreDeterministic(): void
    {
        $subKey1a = $this->masterKey->deriveSubKey(1, 'encrypt_');
        $subKey1b = $this->masterKey->deriveSubKey(1, 'encrypt_');

        self::assertSame($subKey1a, $subKey1b, 'Same subkey ID and context must produce identical keys');
    }

    #[Test]
    public function differentSubkeyIdsProduceDifferentKeys(): void
    {
        $subKey1 = $this->masterKey->deriveSubKey(1, 'encrypt_');
        $subKey2 = $this->masterKey->deriveSubKey(2, 'encrypt_');

        self::assertNotSame($subKey1, $subKey2, 'Different subkey IDs must produce different keys');
    }

    #[Test]
    public function differentContextsProduceDifferentKeys(): void
    {
        $subKeyA = $this->masterKey->deriveSubKey(1, 'encrypt_');
        $subKeyB = $this->masterKey->deriveSubKey(1, 'audit___');

        self::assertNotSame($subKeyA, $subKeyB, 'Different contexts must produce different keys');
    }

    #[Test]
    public function wrongKeyCannotDecrypt(): void
    {
        $otherMasterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        $encryptor = Encryptor::fromMasterKey($this->masterKey);
        $otherEncryptor = Encryptor::fromMasterKey($otherMasterKey);

        $plaintext = 'secret data';
        $ciphertext = $encryptor->encrypt($plaintext);

        $this->expectException(SecurityException::class);
        $otherEncryptor->decrypt($ciphertext);
    }

    #[Test]
    public function hmacRawRoundtripIsConsistent(): void
    {
        $key = random_bytes(32);

        for ($i = 0; $i < 50; $i++) {
            $message = random_int(0, 1) === 0 ? '' : random_bytes(random_int(1, 512));

            $hash = Hmac::compute($message, $key);
            $valid = Hmac::verify($message, $hash, $key);

            self::assertTrue($valid, "HMAC raw roundtrip failed for iteration {$i}");
        }
    }

    #[Test]
    public function keyRotationFallbackDecryption(): void
    {
        $currentHex = sodium_bin2hex(random_bytes(32));
        $previousHex = sodium_bin2hex(random_bytes(32));

        // Encrypt with the old key
        $oldMasterKey = MasterKey::fromHex($previousHex);
        $oldEncryptor = Encryptor::fromMasterKey($oldMasterKey);
        $ciphertext = $oldEncryptor->encrypt('rotated secret');

        // Create new master key with previous key for rotation
        $rotatedMasterKey = MasterKey::fromHex($currentHex, $previousHex);
        $rotatedEncryptor = Encryptor::fromMasterKey($rotatedMasterKey);

        // Should decrypt using fallback to previous key
        $decrypted = $rotatedEncryptor->decrypt($ciphertext);
        self::assertSame('rotated secret', $decrypted);
    }
}
