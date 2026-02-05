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
    public function test_encrypt_decrypt_roundtrip(): void
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
    public function test_aad_mismatch_fails_decryption(): void
    {
        $encryption = $this->createEncryption();

        $plaintext = 'sensitive data';
        $encrypted = $encryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        $this->expectException(SecurityException::class);

        // Decrypt with a different session ID should fail
        $encryption->decrypt($encrypted, 'session-2', 'file', 'example.com');
    }

    #[Test]
    public function test_different_handler_type_aad_fails(): void
    {
        $encryption = $this->createEncryption();

        $plaintext = 'sensitive data';
        $encrypted = $encryption->encrypt($plaintext, 'session-1', 'file', 'example.com');

        $this->expectException(SecurityException::class);

        // Decrypt with a different handler type should fail
        $encryption->decrypt($encrypted, 'session-1', 'redis', 'example.com');
    }

    #[Test]
    public function test_corrupted_ciphertext_fails(): void
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
    public function test_serialization_is_forbidden(): void
    {
        $encryption = $this->createEncryption();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Serialization of SessionEncryption is forbidden');

        serialize($encryption);
    }

    #[Test]
    public function test_key_rotation_support(): void
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
    public function test_empty_plaintext_roundtrip(): void
    {
        $encryption = $this->createEncryption();

        $encrypted = $encryption->encrypt('', 'session-1', 'file', 'example.com');
        $decrypted = $encryption->decrypt($encrypted, 'session-1', 'file', 'example.com');

        self::assertSame('', $decrypted);
    }

    #[Test]
    public function test_different_domain_aad_fails(): void
    {
        $encryption = $this->createEncryption();

        $encrypted = $encryption->encrypt('data', 'session-1', 'file', 'example.com');

        $this->expectException(SecurityException::class);

        $encryption->decrypt($encrypted, 'session-1', 'file', 'evil.com');
    }
}
