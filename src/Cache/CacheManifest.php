<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use JsonException;
use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\HmacInterface;
use SodiumException;
use Throwable;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function hash_equals;
use function hrtime;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function rename;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * HMAC-signed cache manifest.
 *
 * Tracks framework version, environment, invalidation key,
 * per-cache SHA-256 + HMAC, and an overall manifest HMAC.
 * Signing uses canonical JSON (recursively sorted keys, no pretty-print).
 */
#[Internal]
final class CacheManifest
{
    private const string FILENAME = 'manifest.json';

    /**
     * Manifest schema version this build understands.
     *
     * A manifest carrying any other schema_version is rejected at load time:
     * a newer deployment may have written fields this build cannot interpret,
     * and silently coercing them would produce a structurally valid but
     * semantically wrong manifest. Kept in sync with FrameworkCache.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * @param int $schemaVersion Manifest schema version
     * @param string $frameworkVersion Framework version string
     * @param string $appEnv Current application environment
     * @param int $generatedAt Unix timestamp of cache generation
     * @param string $invalidationKey SHA-256 of config + env + version
     * @param string $allowedClassesHash SHA-256 of the allowed_classes.json file
     * @param array<string, array{sha256: string, hmac: string}> $caches Per-cache integrity data
     * @param bool $strict Whether strict mode was used
     * @param bool $encrypted Whether encryption-at-rest is enabled
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $frameworkVersion,
        public readonly string $appEnv,
        public readonly int $generatedAt,
        public readonly string $invalidationKey,
        public readonly string $allowedClassesHash,
        public readonly array $caches,
        public readonly bool $strict,
        public readonly bool $encrypted,
    ) {}

    /**
     * Build, sign, and save a manifest to disk.
     *
     * @param array<string, array{sha256: string, hmac: string}> $caches
     *
     * @throws CacheException If the manifest file cannot be written or renamed.
     * @throws JsonException
     * @throws SodiumException
     */
    public static function write(
        HmacInterface $hmac,
        string $cachePath,
        string $hmacKey,
        int $schemaVersion,
        string $frameworkVersion,
        string $appEnv,
        string $invalidationKey,
        string $allowedClassesHash,
        array $caches,
        bool $strict,
        bool $encrypted,
    ): self {
        $manifest = new self(
            schemaVersion: $schemaVersion,
            frameworkVersion: $frameworkVersion,
            appEnv: $appEnv,
            generatedAt: time(),
            invalidationKey: $invalidationKey,
            allowedClassesHash: $allowedClassesHash,
            caches: $caches,
            strict: $strict,
            encrypted: $encrypted,
        );

        $data = $manifest->toArray();
        $canonicalJson = self::canonicalize($data);
        $hmacValue = $hmac->computeHex($canonicalJson, $hmacKey);

        $data['manifest_hmac'] = $hmacValue;

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;
        self::atomicWrite($path, $json);

        return $manifest;
    }

    /**
     * Atomically write the manifest via temp-file + rename.
     *
     * A bare `file_put_contents(..., LOCK_EX)` only guards against concurrent
     * writers and lets readers observe a partial write (and is purely advisory
     * on Windows / NFS). The temp-file-then-rename pattern guarantees readers
     * see either the old file or the complete new file, never a torn write.
     *
     * @throws CacheException If the manifest cannot be written or renamed.
     */
    private static function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        $pid = getmypid();
        $tmpFile = $dir . DIRECTORY_SEPARATOR . '.tmp.' . ($pid !== false ? $pid : 0) . '.' . hrtime(true);

        $result = file_put_contents($tmpFile, $content, LOCK_EX);

        if ($result === false) {
            throw CacheException::writeFailure($path, 'failed to write temporary manifest file');
        }

        $renamed = @rename($tmpFile, $path);

        if (!$renamed) {
            // Windows fallback: unlink target then rename.
            if (is_file($path)) {
                @unlink($path);
            }

            $renamed = @rename($tmpFile, $path);

            if (!$renamed) {
                @unlink($tmpFile);

                throw CacheException::writeFailure($path, 'failed to rename temporary manifest file');
            }
        }
    }

    /**
     * Load and verify a manifest from disk.
     *
     * @return self|null Null if the manifest is missing, invalid, or signature fails
     *
     * @throws JsonException
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function load(HmacInterface $hmac, string $cachePath, string $hmacKey): ?self
    {
        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */

        $storedHmac = $data['manifest_hmac'] ?? null;

        if (!is_string($storedHmac)) {
            return null;
        }

        // Remove manifest_hmac, reconstruct canonical JSON, verify
        unset($data['manifest_hmac']);
        $canonicalJson = self::canonicalize($data);
        $computedHmac = $hmac->computeHex($canonicalJson, $hmacKey);

        if (!hash_equals($storedHmac, $computedHmac)) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * Get the manifest data as an array (without manifest_hmac).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'framework_version' => $this->frameworkVersion,
            'app_env' => $this->appEnv,
            'generated_at' => $this->generatedAt,
            'invalidation_key' => $this->invalidationKey,
            'allowed_classes_hash' => $this->allowedClassesHash,
            'caches' => $this->caches,
            'strict' => $this->strict,
            'encrypted' => $this->encrypted,
        ];
    }

    /**
     * Reconstruct a manifest from a verified array.
     *
     * @param array{
     *     schema_version?: int,
     *     framework_version?: string,
     *     app_env?: string,
     *     generated_at?: int,
     *     invalidation_key?: string,
     *     allowed_classes_hash?: string,
     *     caches?: array<string, array{sha256: string, hmac: string}>,
     *     strict?: bool|int|string,
     *     encrypted?: bool|int|string,
     * } $data
     */
    private static function fromArray(array $data): ?self
    {
        // Reject any manifest whose schema version this build does not
        // understand. Coercing an unknown version into the current shape
        // would yield a valid-looking but semantically wrong manifest.
        if (($data['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return null;
        }

        try {
            return new self(
                schemaVersion: $data['schema_version'] ?? 0,
                frameworkVersion: $data['framework_version'] ?? '',
                appEnv: $data['app_env'] ?? '',
                generatedAt: $data['generated_at'] ?? 0,
                invalidationKey: $data['invalidation_key'] ?? '',
                allowedClassesHash: $data['allowed_classes_hash'] ?? '',
                caches: $data['caches'] ?? [],
                strict: (bool) ($data['strict'] ?? false),
                encrypted: (bool) ($data['encrypted'] ?? false),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Canonicalize an array to deterministic JSON.
     *
     * Recursively sorts keys, uses no pretty-print, and produces
     * stable encoding regardless of platform or insertion order.
     *
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    #[NoDiscard]
    public static function canonicalize(array $data): string
    {
        self::recursiveKsort($data);

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively sort array keys.
     *
     * @param array<string, mixed> $data
     */
    private static function recursiveKsort(array &$data): void
    {
        ksort($data);

        /** @var mixed $value */
        foreach ($data as &$value) {
            if (is_array($value)) {
                // Only sort associative arrays (string keys), not lists
                if ($value !== [] && !array_is_list($value)) {
                    /** @var array<string, mixed> $value */
                    self::recursiveKsort($value);
                }
            }
        }
    }
}
