<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheException;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;

use function hash;
use function strlen;

#[CoversClass(CacheIntegrity::class)]
final class CacheIntegrityTest extends TestCase
{
    private string $hmacKey;
    private CacheIntegrity $integrity;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->hmacKey = random_bytes(32);
        $this->integrity = new CacheIntegrity(new HmacService(), $this->hmacKey);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cache_integrity_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function signReturnsSha256AndHmac(): void
    {
        $payload = 'test payload data';
        $result = $this->integrity->sign($payload);

        self::assertArrayHasKey('sha256', $result);
        self::assertArrayHasKey('hmac', $result);

        // SHA-256 produces 64 hex characters
        self::assertSame(64, strlen($result['sha256']));

        // BLAKE2b-256 HMAC produces 64 hex characters
        self::assertSame(64, strlen($result['hmac']));

        // SHA-256 matches expected value
        self::assertSame(hash('sha256', $payload), $result['sha256']);
    }

    #[Test]
    public function signReturnsDeterministicResults(): void
    {
        $payload = 'deterministic test';
        $result1 = $this->integrity->sign($payload);
        $result2 = $this->integrity->sign($payload);

        self::assertSame($result1['sha256'], $result2['sha256']);
        self::assertSame($result1['hmac'], $result2['hmac']);
    }

    #[Test]
    public function verifyReturnsTrueForValidPayload(): void
    {
        $payload = 'valid payload';
        $sig = $this->integrity->sign($payload);

        self::assertTrue($this->integrity->verify($payload, $sig['sha256'], $sig['hmac']));
    }

    #[Test]
    public function verifyReturnsFalseForTamperedPayload(): void
    {
        $payload = 'original payload';
        $sig = $this->integrity->sign($payload);

        self::assertFalse($this->integrity->verify('tampered payload', $sig['sha256'], $sig['hmac']));
    }

    #[Test]
    public function verifyReturnsFalseForWrongHmac(): void
    {
        $payload = 'test payload';
        $sig = $this->integrity->sign($payload);

        $wrongHmac = str_repeat('a', 64);

        self::assertFalse($this->integrity->verify($payload, $sig['sha256'], $wrongHmac));
    }

    #[Test]
    public function verifyReturnsFalseForWrongSha256(): void
    {
        $payload = 'test payload';
        $sig = $this->integrity->sign($payload);

        $wrongSha256 = str_repeat('b', 64);

        self::assertFalse($this->integrity->verify($payload, $wrongSha256, $sig['hmac']));
    }

    #[Test]
    public function verifyUsesConstantTimeComparisonWithSimilarValues(): void
    {
        $payload = 'test payload for timing';
        $sig = $this->integrity->sign($payload);

        // Create a value that differs only in the last character
        $almostCorrectHmac = substr($sig['hmac'], 0, -1) . ($sig['hmac'][-1] === 'a' ? 'b' : 'a');

        self::assertFalse($this->integrity->verify($payload, $sig['sha256'], $almostCorrectHmac));

        // Create a sha256 that differs only in the first character
        $almostCorrectSha256 = ($sig['sha256'][0] === 'a' ? 'b' : 'a') . substr($sig['sha256'], 1);

        self::assertFalse($this->integrity->verify($payload, $almostCorrectSha256, $sig['hmac']));
    }

    #[Test]
    public function writeEnvelopeAndReadEnvelopeRoundTripUnencrypted(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'test.cache.bin';
        $data = ['key' => 'value', 'number' => 42];
        $serialized = serialize($data);

        $this->integrity->writeEnvelope($path, $serialized, false);

        self::assertFileExists($path);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertSame($data, $result);
    }

    #[Test]
    public function writeEnvelopeAndReadEnvelopeRoundTripEncrypted(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryptor = Encryptor::fromDerivedKey($masterKey, 8, 'fw_c_enc');
        $integrity = new CacheIntegrity(new HmacService(), $this->hmacKey, $encryptor);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'encrypted.cache.bin';
        $data = ['secret' => 'classified', 'level' => 5];
        $serialized = serialize($data);

        $integrity->writeEnvelope($path, $serialized, true);

        self::assertFileExists($path);

        $result = $integrity->readEnvelope($path, []);

        self::assertSame($data, $result);
    }

    #[Test]
    public function readEnvelopeReturnsNullForCorruptedData(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'corrupted.cache.bin';
        file_put_contents($path, 'not valid serialized data at all');

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function readEnvelopeReturnsNullForWrongSchema(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'wrong_schema.cache.bin';

        // Write an envelope with schema=99 (wrong)
        $envelope = serialize([
            'schema' => 99,
            'payload' => serialize(['data' => true]),
            'sha256' => str_repeat('a', 64),
            'hmac' => str_repeat('b', 64),
            'encrypted' => false,
        ]);
        file_put_contents($path, $envelope);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function readEnvelopeReturnsNullWhenEncryptedButNoEncryptor(): void
    {
        // Write encrypted envelope with an encryptor
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $encryptor = Encryptor::fromDerivedKey($masterKey, 8, 'fw_c_enc');
        $encryptedIntegrity = new CacheIntegrity(new HmacService(), $this->hmacKey, $encryptor);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'no_decryptor.cache.bin';
        $encryptedIntegrity->writeEnvelope($path, serialize(['data' => true]), true);

        // Try to read with integrity that has no encryptor
        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function validateFileRejectsSymlinks(): void
    {
        $realFile = $this->tempDir . DIRECTORY_SEPARATOR . 'real.bin';
        $symlink = $this->tempDir . DIRECTORY_SEPARATOR . 'link.bin';
        file_put_contents($realFile, 'data');
        symlink($realFile, $symlink);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('symlink');

        $this->integrity->validateFile($symlink);
    }

    #[Test]
    #[RequiresOperatingSystemFamily('Linux')]
    public function validateDirectoryRejectsSymlinks(): void
    {
        $realDir = $this->tempDir . DIRECTORY_SEPARATOR . 'realdir';
        $symlinkDir = $this->tempDir . DIRECTORY_SEPARATOR . 'linkdir';
        mkdir($realDir, 0o750);
        symlink($realDir, $symlinkDir);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('symlink');

        $this->integrity->validateDirectory($symlinkDir);
    }

    #[Test]
    public function validateFileRejectsNonExistentFile(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('not a regular file');

        $this->integrity->validateFile($this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent.bin');
    }

    #[Test]
    public function validateDirectoryRejectsNonDirectory(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'afile.txt';
        file_put_contents($file, 'data');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('not a directory');

        $this->integrity->validateDirectory($file);
    }

    #[Test]
    public function writeEnvelopeCreatesDirectoryIfMissing(): void
    {
        $nestedDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deep';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'test.cache.bin';
        $serialized = serialize(['test' => true]);

        $this->integrity->writeEnvelope($path, $serialized, false);

        self::assertFileExists($path);
        self::assertDirectoryExists($nestedDir);
    }

    #[Test]
    public function differentHmacKeysProduceDifferentSignatures(): void
    {
        $key2 = random_bytes(32);
        $integrity2 = new CacheIntegrity(new HmacService(), $key2);

        $payload = 'same payload';
        $sig1 = $this->integrity->sign($payload);
        $sig2 = $integrity2->sign($payload);

        // SHA-256 is the same (no key involved)
        self::assertSame($sig1['sha256'], $sig2['sha256']);

        // HMAC differs because keys differ
        self::assertNotSame($sig1['hmac'], $sig2['hmac']);
    }

    #[Test]
    public function readEnvelopeFailsWithDifferentHmacKey(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'key_mismatch.cache.bin';
        $this->integrity->writeEnvelope($path, serialize(['data' => true]), false);

        // Read with a different key
        $differentKey = random_bytes(32);
        $differentIntegrity = new CacheIntegrity(new HmacService(), $differentKey);

        $result = $differentIntegrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function readEnvelopeReturnsNullForNonExistentFile(): void
    {
        $result = $this->integrity->readEnvelope(
            $this->tempDir . DIRECTORY_SEPARATOR . 'does_not_exist.cache.bin',
            [],
        );

        self::assertNull($result);
    }

    #[Test]
    public function readEnvelopeReturnsNullWhenEnvelopeFieldsAreNonStrings(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'bad_types.cache.bin';

        // Write an envelope with non-string payload
        $envelope = serialize([
            'schema' => 1,
            'payload' => 12345,
            'sha256' => 'abc',
            'hmac' => 'def',
            'encrypted' => false,
        ]);
        file_put_contents($path, $envelope);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function readEnvelopeReturnsNullForTamperedPayload(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered.cache.bin';
        $this->integrity->writeEnvelope($path, serialize(['data' => true]), false);

        // Read the file, tamper the payload inside the envelope
        $raw = file_get_contents($path);
        self::assertNotFalse($raw);

        $envelope = unserialize($raw, ['allowed_classes' => false]);
        self::assertIsArray($envelope);

        $envelope['payload'] = serialize(['data' => 'tampered']);
        file_put_contents($path, serialize($envelope));

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function signProducesDifferentHmacsForDifferentPayloads(): void
    {
        $sig1 = $this->integrity->sign('payload one');
        $sig2 = $this->integrity->sign('payload two');

        self::assertNotSame($sig1['sha256'], $sig2['sha256']);
        self::assertNotSame($sig1['hmac'], $sig2['hmac']);
    }

    #[Test]
    public function writeEnvelopeOverwritesExistingFile(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'overwrite.cache.bin';
        $this->integrity->writeEnvelope($path, serialize(['version' => 1]), false);
        $this->integrity->writeEnvelope($path, serialize(['version' => 2]), false);

        $result = $this->integrity->readEnvelope($path, []);

        self::assertSame(['version' => 2], $result);
    }

    #[Test]
    public function writeEnvelopeWithEncryptionFlagButNoEncryptorWritesPlaintext(): void
    {
        // Integrity without encryptor
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'no_enc.cache.bin';
        $this->integrity->writeEnvelope($path, serialize(['data' => 'plain']), true);

        // Should still be readable because encrypt flag is true but no encryptor available
        $result = $this->integrity->readEnvelope($path, []);

        self::assertSame(['data' => 'plain'], $result);
    }

    #[Test]
    public function readEnvelopeReturnsNullForEmptyFileContents(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'empty.cache.bin';
        file_put_contents($path, '');

        $result = $this->integrity->readEnvelope($path, []);

        self::assertNull($result);
    }

    #[Test]
    public function validateDirectoryAcceptsValidDirectory(): void
    {
        // Should not throw for our temp directory
        $this->expectNotToPerformAssertions();

        $this->integrity->validateDirectory($this->tempDir);
    }

    #[Test]
    public function validateDirectoryRejectsNonExistentDirectory(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('not a directory');

        $this->integrity->validateDirectory($this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent');
    }

    #[Test]
    public function validateFileAcceptsValidFile(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'valid.bin';
        file_put_contents($path, 'data');

        $this->expectNotToPerformAssertions();

        // Should not throw
        $this->integrity->validateFile($path);
    }

    #[Test]
    public function signEmptyPayload(): void
    {
        $result = $this->integrity->sign('');

        self::assertSame(64, strlen($result['sha256']));
        self::assertSame(64, strlen($result['hmac']));
        self::assertSame(hash('sha256', ''), $result['sha256']);
    }

    #[Test]
    public function verifyReturnsTrueForEmptyPayload(): void
    {
        $sig = $this->integrity->sign('');

        self::assertTrue($this->integrity->verify('', $sig['sha256'], $sig['hmac']));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
