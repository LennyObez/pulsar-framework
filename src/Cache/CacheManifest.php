<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use function file_get_contents;
use function file_put_contents;
use function hash_equals;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;

use function ksort;

use const LOCK_EX;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use SodiumException;
use Throwable;

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
     * @throws JsonException
     * @throws SodiumException
     */
    public static function write(
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
        $hmac = Hmac::computeHex($canonicalJson, $hmacKey);

        $data['manifest_hmac'] = $hmac;

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = $cachePath . DIRECTORY_SEPARATOR . self::FILENAME;
        file_put_contents($path, $json, LOCK_EX);

        return $manifest;
    }

    /**
     * Load and verify a manifest from disk.
     *
     * @return self|null Null if the manifest is missing, invalid, or signature fails
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public static function load(string $cachePath, string $hmacKey): ?self
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
        $computedHmac = Hmac::computeHex($canonicalJson, $hmacKey);

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
     * @param array<string, mixed> $data
     */
    private static function fromArray(array $data): ?self
    {
        try {
            /** @var array<string, array{sha256: string, hmac: string}> $caches */
            $caches = $data['caches'] ?? [];

            $rawSchemaVersion = $data['schema_version'] ?? 0;
            $rawFrameworkVersion = $data['framework_version'] ?? '';
            $rawAppEnv = $data['app_env'] ?? '';
            $rawGeneratedAt = $data['generated_at'] ?? 0;
            $rawInvalidationKey = $data['invalidation_key'] ?? '';
            $rawAllowedClassesHash = $data['allowed_classes_hash'] ?? '';

            return new self(
                schemaVersion: is_int($rawSchemaVersion) ? $rawSchemaVersion : 0,
                frameworkVersion: is_string($rawFrameworkVersion) ? $rawFrameworkVersion : '',
                appEnv: is_string($rawAppEnv) ? $rawAppEnv : '',
                generatedAt: is_int($rawGeneratedAt) ? $rawGeneratedAt : 0,
                invalidationKey: is_string($rawInvalidationKey) ? $rawInvalidationKey : '',
                allowedClassesHash: is_string($rawAllowedClassesHash) ? $rawAllowedClassesHash : '',
                caches: $caches,
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
