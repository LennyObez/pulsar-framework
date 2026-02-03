<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function getmypid;
use function hash;
use function hash_equals;
use function hrtime;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;

use const LOCK_EX;

use function mkdir;

use const PHP_OS_FAMILY;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\Hmac;
use Random\RandomException;

use function rename;

use SodiumException;
use Throwable;

use function unlink;

/**
 * Cache integrity: HMAC signing, verification, and filesystem hardening.
 *
 * Uses BLAKE2b HMAC via MasterKey subkey (subKeyId=7, context=fw_cache)
 * for signing and verification. All comparisons use hash_equals().
 */
#[Internal]
final readonly class CacheIntegrity
{
    public function __construct(
        private string $hmacKey,
        private ?Encryptor $encryptor = null,
    ) {}

    /**
     * Sign a payload: compute SHA-256, then HMAC the hash.
     *
     * @return array{sha256: string, hmac: string}
     *
     * @throws SodiumException
     */
    public function sign(string $payload): array
    {
        $sha256 = hash('sha256', $payload);
        $hmac = Hmac::computeHex($sha256, $this->hmacKey);

        return ['sha256' => $sha256, 'hmac' => $hmac];
    }

    /**
     * Verify a payload against its expected SHA-256 and HMAC.
     *
     * Uses constant-time comparison for both hash and HMAC.
     *
     * @throws SodiumException
     */
    public function verify(string $payload, string $expectedSha256, string $expectedHmac): bool
    {
        $actualSha256 = hash('sha256', $payload);

        if (!hash_equals($expectedSha256, $actualSha256)) {
            return false;
        }

        $actualHmac = Hmac::computeHex($actualSha256, $this->hmacKey);

        return hash_equals($expectedHmac, $actualHmac);
    }

    /**
     * Write a signed cache envelope to disk.
     *
     * Format: serialized envelope with schema, payload, sha256, hmac, encrypted flag.
     * The outer envelope uses ['allowed_classes' => false] on read.
     *
     * @throws CacheException
     * @throws RandomException If nonce generation fails during encryption
     * @throws SodiumException
     */
    public function writeEnvelope(string $path, string $serializedPayload, bool $encrypt): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw CacheException::writeFailure($path, 'failed to create directory');
        }

        $payloadToStore = $serializedPayload;

        if ($encrypt && $this->encryptor !== null) {
            $payloadToStore = $this->encryptor->encrypt($serializedPayload);
        }

        $sig = $this->sign($payloadToStore);

        $envelope = serialize([
            'schema' => 1,
            'payload' => $payloadToStore,
            'sha256' => $sig['sha256'],
            'hmac' => $sig['hmac'],
            'encrypted' => $encrypt && $this->encryptor !== null,
        ]);

        $this->atomicWrite($path, $envelope);
    }

    /**
     * Read and verify a signed cache envelope from disk.
     *
     * @param list<string> $allowedClasses Classes allowed for inner payload deserialization
     * @return mixed The deserialized payload, or null if verification fails
     *
     * @throws CacheException
     * @throws SodiumException
     */
    public function readEnvelope(string $path, array $allowedClasses): mixed
    {
        if (!is_file($path)) {
            return null;
        }

        $this->validateFile($path);

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        // Outer envelope: no class instantiation
        $envelope = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($envelope)) {
            return null;
        }

        if (($envelope['schema'] ?? null) !== 1) {
            return null;
        }

        $payloadData = $envelope['payload'] ?? null;
        $expectedSha256 = $envelope['sha256'] ?? null;
        $expectedHmac = $envelope['hmac'] ?? null;
        $encrypted = $envelope['encrypted'] ?? false;

        if (!is_string($payloadData) || !is_string($expectedSha256) || !is_string($expectedHmac)) {
            return null;
        }

        // Verify HMAC of stored payload (before decryption)
        if (!$this->verify($payloadData, $expectedSha256, $expectedHmac)) {
            return null;
        }

        // Decrypt if needed
        $serialized = $payloadData;
        if ($encrypted) {
            if ($this->encryptor === null) {
                return null;
            }

            try {
                $serialized = $this->encryptor->decrypt($payloadData);
            } catch (Throwable) {
                return null;
            }
        }

        // Inner deserialization with restricted class allowlist
        return @unserialize($serialized, ['allowed_classes' => $allowedClasses]);
    }

    /**
     * Validate that a cache file is safe to read.
     *
     * Rejects symlinks, non-regular files, and (on Unix) world-writable files.
     *
     * @throws CacheException
     */
    public function validateFile(string $path): void
    {
        if (is_link($path)) {
            throw CacheException::corruptedCache($path, 'file is a symlink');
        }

        if (!is_file($path)) {
            throw CacheException::corruptedCache($path, 'not a regular file');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms($path);
            if ($perms !== false && ($perms & 0o002) !== 0) {
                throw CacheException::corruptedCache($path, 'file is world-writable');
            }
        }
    }

    /**
     * Validate that the cache directory is safe.
     *
     * Rejects symlinks, non-directories, and (on Unix) world-writable directories.
     *
     * @throws CacheException
     */
    public function validateDirectory(string $dir): void
    {
        if (is_link($dir)) {
            throw CacheException::directoryInvalid($dir, 'directory is a symlink');
        }

        if (!is_dir($dir)) {
            throw CacheException::directoryInvalid($dir, 'not a directory');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms($dir);
            if ($perms !== false && ($perms & 0o002) !== 0) {
                throw CacheException::directoryInvalid($dir, 'directory is world-writable');
            }
        }
    }

    /**
     * Atomic file write with cross-platform safety.
     *
     * @throws CacheException
     */
    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        $pid = getmypid();
        $tmpFile = $dir . DIRECTORY_SEPARATOR . '.tmp.' . ($pid !== false ? $pid : 0) . '.' . hrtime(true);

        $result = file_put_contents($tmpFile, $content, LOCK_EX);

        if ($result === false) {
            throw CacheException::writeFailure($path, 'failed to write temporary file');
        }

        // Atomic rename (cross-platform)
        $renamed = @rename($tmpFile, $path);

        if (!$renamed) {
            // Windows fallback: unlink target then rename
            if (is_file($path)) {
                @unlink($path);
            }

            $renamed = @rename($tmpFile, $path);

            if (!$renamed) {
                @unlink($tmpFile);
                throw CacheException::writeFailure($path, 'failed to rename temporary file');
            }
        }
    }
}
