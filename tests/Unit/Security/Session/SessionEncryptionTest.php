<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Session\SessionEncryption;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(SessionEncryption::class)]
final class SessionEncryptionTest extends TestCase
{
    private function createEncryption(): SessionEncryption
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        return SessionEncryption::fromMasterKey($masterKey);
    }

    #[Test]
    public function encryptDecryptRoundtrip(): void
    {
        $encryption = $this->createEncryption();

        $plaintext = 'session data with sensitive information';
        $sessionId = 'test-session-123';
        $handlerType = 'file';
        $domain = 'example.com';

        $encrypted = $encryption->encrypt($plaintext, $sessionId, $handlerType, $domain);

        self::assertNotSame($plaintext, $encrypted);

        $decrypted = $encryption->decrypt($encrypted, $sessionId, $handlerType, $domain);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function aadMismatchFailsDecryption(): void
    {
        $encryption = $this->createEncryption();

        $plaintext = 'sensitive data';
        $encrypted = $encryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        $this->expectException(SecurityException::class);

        // Decrypt with a different session ID should fail
        $encryption->decrypt($encrypted, 'session-2', 'file', 'example.com');
    }

    #[Test]
    public function differentHandlerTypeAadFails(): void
    {
        $encryption = $this->createEncryption();

        $plaintext = 'sensitive data';
        $encrypted = $encryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        $this->expectException(SecurityException::class);

        // Decrypt with a different handler type should fail
        $encryption->decrypt($encrypted, 'session-1', 'redis', 'example.com');
    }

    #[Test]
    public function corruptedCiphertextFails(): void
    {
        $encryption = $this->createEncryption();

        $plaintext = 'sensitive data';
        $encrypted = $encryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        // Corrupt the encrypted data by replacing characters in the middle
        $corrupted = substr($encrypted, 0, 10) . 'CORRUPTED' . substr($encrypted, 19);

        $this->expectException(SecurityException::class);

        $encryption->decrypt($corrupted, 'session-1', 'file', 'example.com');
    }

    #[Test]
    public function serializationIsForbidden(): void
    {
        $encryption = $this->createEncryption();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization of SessionEncryption is forbidden');

        serialize($encryption);
    }

    #[Test]
    public function keyRotationSupport(): void
    {
        // Create a master key with a previous key for rotation
        $currentKeyHex = sodium_bin2hex(random_bytes(32));
        $previousKeyHex = sodium_bin2hex(random_bytes(32));

        // Create encryption from previous key (simulates old data)
        $oldMasterKey = MasterKey::fromHex($previousKeyHex);
        $oldEncryption = SessionEncryption::fromMasterKey($oldMasterKey);

        $plaintext = 'data encrypted with old key';
        $encrypted = $oldEncryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        // Create encryption with current + previous key (rotation window)
        $rotatedMasterKey = MasterKey::fromHex($currentKeyHex, $previousKeyHex);
        $newEncryption = SessionEncryption::fromMasterKey($rotatedMasterKey);

        // New key should be able to encrypt new data
        $newEncrypted = $newEncryption->encrypt('new data', 'session-2', 'file', 'example.com');
        self::assertSame('new data', $newEncryption->decrypt($newEncrypted, 'session-2', 'file', 'example.com'));
    }

    #[Test]
    public function emptyPlaintextRoundtrip(): void
    {
        $encryption = $this->createEncryption();

        $encrypted = $encryption->encrypt('', 'session-1', 'file', 'example.com');
        $decrypted = $encryption->decrypt($encrypted, 'session-1', 'file', 'example.com');

        self::assertSame('', $decrypted);
    }

    #[Test]
    public function differentDomainAadFails(): void
    {
        $encryption = $this->createEncryption();

        $encrypted = $encryption->encrypt('data', 'session-1', 'file', 'example.com');

        $this->expectException(SecurityException::class);

        $encryption->decrypt($encrypted, 'session-1', 'file', 'evil.com');
    }

    #[Test]
    public function decryptRejectsInvalidBase64(): void
    {
        $encryption = $this->createEncryption();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('invalid base64 encoding');

        $encryption->decrypt('not!valid!base64!!!', 'session-1', 'file', 'example.com');
    }

    #[Test]
    public function decryptRejectsTooShortCiphertext(): void
    {
        $encryption = $this->createEncryption();

        // Valid base64 but too short to contain kid + nonce + tag
        $tooShort = base64_encode('short');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('ciphertext too short');

        $encryption->decrypt($tooShort, 'session-1', 'file', 'example.com');
    }

    #[Test]
    public function decryptRejectsUnknownKeyId(): void
    {
        $encryption = $this->createEncryption();

        // Create a valid-length payload with a bogus key ID
        $fakeKid = str_repeat("\x00", 8);
        $fakeNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $fakeCiphertext = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES + 10);

        $encoded = base64_encode($fakeKid . $fakeNonce . $fakeCiphertext);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('unknown key identifier');

        $encryption->decrypt($encoded, 'session-1', 'file', 'example.com');
    }

    #[Test]
    public function debugInfoRedactsKey(): void
    {
        $encryption = $this->createEncryption();

        $debugInfo = $encryption->__debugInfo();

        self::assertArrayHasKey('currentKid', $debugInfo);
        self::assertArrayHasKey('currentKey', $debugInfo);
        self::assertSame('[REDACTED]', $debugInfo['currentKey']);
        self::assertNotEmpty($debugInfo['currentKid']);
    }

    #[Test]
    public function unserializeIsForbidden(): void
    {
        $encryption = $this->createEncryption();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessageIsOrContains('Serialization of SessionEncryption is forbidden');

        $encryption->__unserialize([]);
    }

    #[Test]
    public function encryptProducesDifferentCiphertextsForSameInput(): void
    {
        $encryption = $this->createEncryption();

        $encrypted1 = $encryption->encrypt('same data', 'session-1', 'file', 'example.com');
        $encrypted2 = $encryption->encrypt('same data', 'session-1', 'file', 'example.com');

        // Different nonces should produce different ciphertexts
        self::assertNotSame($encrypted1, $encrypted2);

        // But both decrypt to the same value
        self::assertSame('same data', $encryption->decrypt($encrypted1, 'session-1', 'file', 'example.com'));
        self::assertSame('same data', $encryption->decrypt($encrypted2, 'session-1', 'file', 'example.com'));
    }

    #[Test]
    public function largePayloadRoundTrip(): void
    {
        $encryption = $this->createEncryption();

        $largeData = str_repeat('large session data block ', 1000);
        $encrypted = $encryption->encrypt($largeData, 'session-1', 'file', 'example.com');
        $decrypted = $encryption->decrypt($encrypted, 'session-1', 'file', 'example.com');

        self::assertSame($largeData, $decrypted);
    }
}
