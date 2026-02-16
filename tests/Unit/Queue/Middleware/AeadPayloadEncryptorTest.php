<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Middleware\AeadPayloadEncryptor;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\Crypto\MasterKey;

use function random_bytes;
use function sodium_bin2hex;

#[CoversClass(AeadPayloadEncryptor::class)]
final class AeadPayloadEncryptorTest extends TestCase
{
    private MasterKey $masterKey;
    private AeadPayloadEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));

        $keyId = $this->masterKey->keyId(10, 'que_aead');
        $derivedKey = $this->masterKey->deriveSubKey(10, 'que_aead', 32);
        $keyRing = new EnvKeyRing([$keyId => $derivedKey]);

        $this->encryptor = new AeadPayloadEncryptor($this->masterKey, $keyRing);
    }

    #[Test]
    public function encryption_round_trip_succeeds(): void
    {
        $plaintext = '{"user_id":42,"action":"transfer","amount":1000}';
        $aad = AeadPayloadEncryptor::composeAad('tenant-1', 'payments', 'App\\Jobs\\Transfer', 1, 'corr-abc');

        $result = $this->encryptor->encrypt($plaintext, $aad);

        self::assertNotSame($plaintext, $result['ciphertext']);
        self::assertNotEmpty($result['keyId']);

        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $aad, $result['keyId']);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function encryption_produces_different_ciphertext_each_time(): void
    {
        $plaintext = '{"data":"same_data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'c1');

        $result1 = $this->encryptor->encrypt($plaintext, $aad);
        $result2 = $this->encryptor->encrypt($plaintext, $aad);

        self::assertNotSame($result1['ciphertext'], $result2['ciphertext']);
    }

    #[Test]
    public function tampering_with_tenant_id_in_aad_causes_decryption_failure(): void
    {
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('tenant-1', 'default', 'App\\Jobs\\Foo', 1, 'corr-1');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        $tamperedAad = AeadPayloadEncryptor::composeAad('tenant-EVIL', 'default', 'App\\Jobs\\Foo', 1, 'corr-1');

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('authentication tag mismatch');
        $this->encryptor->decrypt($result['ciphertext'], $tamperedAad, $result['keyId']);
    }

    #[Test]
    public function tampering_with_queue_name_in_aad_causes_decryption_failure(): void
    {
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'payments', 'App\\Jobs\\Foo', 1, 'corr-1');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        $tamperedAad = AeadPayloadEncryptor::composeAad('t1', 'hacked-queue', 'App\\Jobs\\Foo', 1, 'corr-1');

        $this->expectException(QueueException::class);
        $this->encryptor->decrypt($result['ciphertext'], $tamperedAad, $result['keyId']);
    }

    #[Test]
    public function tampering_with_job_class_in_aad_causes_decryption_failure(): void
    {
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'App\\Jobs\\Legit', 1, 'corr-1');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        $tamperedAad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'App\\Jobs\\Evil', 1, 'corr-1');

        $this->expectException(QueueException::class);
        $this->encryptor->decrypt($result['ciphertext'], $tamperedAad, $result['keyId']);
    }

    #[Test]
    public function tampering_with_schema_version_in_aad_causes_decryption_failure(): void
    {
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-1');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        $tamperedAad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 999, 'corr-1');

        $this->expectException(QueueException::class);
        $this->encryptor->decrypt($result['ciphertext'], $tamperedAad, $result['keyId']);
    }

    #[Test]
    public function tampering_with_correlation_id_in_aad_causes_decryption_failure(): void
    {
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-real');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        $tamperedAad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-fake');

        $this->expectException(QueueException::class);
        $this->encryptor->decrypt($result['ciphertext'], $tamperedAad, $result['keyId']);
    }

    #[Test]
    public function decryption_succeeds_across_retry_attempts(): void
    {
        // Encrypt at dispatch time (attempt 1)
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-1');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        // Attempt is NOT in AAD, so decryption with same AAD must succeed on retry
        $decrypted = $this->encryptor->decrypt($result['ciphertext'], $aad, $result['keyId']);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function key_rotation_decrypts_with_previous_key(): void
    {
        // Encrypt with the "old" key
        $oldKeyHex = sodium_bin2hex(random_bytes(32));
        $oldMasterKey = MasterKey::fromHex($oldKeyHex);
        $oldKeyId = $oldMasterKey->keyId(10, 'que_aead');
        $oldDerivedKey = $oldMasterKey->deriveSubKey(10, 'que_aead', 32);
        $oldKeyRing = new EnvKeyRing([$oldKeyId => $oldDerivedKey]);
        $oldEncryptor = new AeadPayloadEncryptor($oldMasterKey, $oldKeyRing);

        $plaintext = '{"rotation":"test"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-1');
        $result = $oldEncryptor->encrypt($plaintext, $aad);

        // Create a new master key with old key as previous (simulating rotation)
        $newKeyHex = sodium_bin2hex(random_bytes(32));
        $rotatedMasterKey = MasterKey::fromHex($newKeyHex, $oldKeyHex);
        $newKeyId = $rotatedMasterKey->keyId(10, 'que_aead');
        $newDerivedKey = $rotatedMasterKey->deriveSubKey(10, 'que_aead', 32);
        $newKeyRing = new EnvKeyRing([$newKeyId => $newDerivedKey]);
        $rotatedEncryptor = new AeadPayloadEncryptor($rotatedMasterKey, $newKeyRing);

        // Decrypt with the new encryptor (should fallback to previous key)
        $decrypted = $rotatedEncryptor->decrypt($result['ciphertext'], $aad, $result['keyId']);

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function decryption_fails_with_completely_wrong_key(): void
    {
        $plaintext = '{"secret":"data"}';
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-1');
        $result = $this->encryptor->encrypt($plaintext, $aad);

        // Create encryptor with a completely different key
        $wrongKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $wrongKeyRing = new EnvKeyRing([]);
        $wrongEncryptor = new AeadPayloadEncryptor($wrongKey, $wrongKeyRing);

        $this->expectException(QueueException::class);
        $wrongEncryptor->decrypt($result['ciphertext'], $aad, $result['keyId']);
    }

    #[Test]
    public function decryption_fails_with_invalid_ciphertext_format(): void
    {
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-1');

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('invalid ciphertext format');
        $this->encryptor->decrypt('not-valid-base64!@#$', $aad, null);
    }

    #[Test]
    public function decryption_fails_with_truncated_ciphertext(): void
    {
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr-1');

        // Base64 of just a few bytes (too short for nonce + tag)
        $this->expectException(QueueException::class);
        $this->encryptor->decrypt(base64_encode('short'), $aad, null);
    }

    #[Test]
    public function compose_aad_produces_deterministic_output(): void
    {
        $aad1 = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr');
        $aad2 = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr');

        self::assertSame($aad1, $aad2);
        self::assertSame('t1|q1|Job|1|corr', $aad1);
    }

    #[Test]
    public function compose_aad_handles_null_tenant_id(): void
    {
        $aad = AeadPayloadEncryptor::composeAad(null, 'q1', 'Job', 1, 'corr');

        self::assertSame('|q1|Job|1|corr', $aad);
    }

    #[Test]
    public function compose_aad_excludes_attempt_number(): void
    {
        // The AAD must NOT include attempt number so retries can decrypt
        $aad = AeadPayloadEncryptor::composeAad('t1', 'q1', 'Job', 1, 'corr');

        self::assertStringNotContainsString('|1|1', $aad . '|END');
        self::assertSame('t1|q1|Job|1|corr', $aad);
    }
}
