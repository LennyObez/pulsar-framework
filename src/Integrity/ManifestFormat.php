<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Integrity\Exception\IntegrityException;

use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Serializes and deserializes integrity manifests to/from JSON.
 * @api
 */
#[Api(since: '1.0.0')]
final class ManifestFormat
{
    /**
     * Serialize an integrity manifest to formatted JSON.
     *
     * @param IntegrityManifest $manifest The manifest to serialize
     * @param string|null       $signature Optional HMAC signature to embed
     *
     * @throws JsonException
     * @throws IntegrityException If the manifest declares no scope. Writing one
     *         out with an empty scope would produce a document that covers
     *         nothing and reports no additions, however the tree changes.
     */
    #[NoDiscard]
    public static function toJson(IntegrityManifest $manifest, ?string $signature = null): string
    {
        if ($manifest->scope === null) {
            throw IntegrityException::scopeMissing();
        }

        $entries = array_map(
            static fn(ManifestEntry $entry): array => [
                'path' => $entry->path,
                'hash' => $entry->hash,
                'size' => $entry->size,
            ],
            $manifest->entries,
        );

        $data = [
            'version' => $manifest->version,
            'algorithm' => $manifest->algorithm,
            'generated_at' => $manifest->generatedAt,
            'framework_version' => $manifest->frameworkVersion,
            'entry_count' => $manifest->entryCount,
            'scope' => [
                'include' => $manifest->scope->include,
                'exclude' => $manifest->scope->exclude,
            ],
            'entries' => $entries,
        ];

        if ($signature !== null) {
            $data['signature'] = $signature;
        } elseif ($manifest->signature !== null) {
            $data['signature'] = $manifest->signature;
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Deserialize a JSON string into an IntegrityManifest.
     *
     * @throws IntegrityException If the JSON is malformed or missing required fields
     */
    #[NoDiscard]
    public static function fromJson(string $json): IntegrityManifest
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw IntegrityException::manifestCorrupted('(string)', 'invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw IntegrityException::manifestCorrupted('(string)', 'expected JSON object at root');
        }

        if (!isset($data['version']) || !is_int($data['version'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "version" field');
        }

        // A version 1 manifest carries no scope. Accepting one would put the
        // verifier back to inferring which files ought to exist from the entries
        // it happens to hold, which is how a file dropped into an entry-less
        // directory went unnoticed.
        if ($data['version'] !== IntegrityManifest::VERSION) {
            throw IntegrityException::manifestCorrupted(
                '(string)',
                'unsupported schema version ' . $data['version']
                . ' (expected ' . IntegrityManifest::VERSION . '); regenerate with "php bin/pulsar integrity:build"',
            );
        }

        if (!isset($data['algorithm']) || !is_string($data['algorithm'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "algorithm" field');
        }

        if (!isset($data['generated_at']) || !is_int($data['generated_at'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "generated_at" field');
        }

        if (!isset($data['framework_version']) || !is_string($data['framework_version'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "framework_version" field');
        }

        if (!isset($data['entry_count']) || !is_int($data['entry_count'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "entry_count" field');
        }

        if (!isset($data['entries']) || !is_array($data['entries'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "entries" field');
        }

        if (!isset($data['scope']) || !is_array($data['scope'])) {
            throw IntegrityException::manifestCorrupted('(string)', 'missing or invalid "scope" field');
        }

        /** @var array<string, mixed> $rawScope */
        $rawScope = $data['scope'];

        $scope = new ManifestScope(
            include: self::parsePatterns($rawScope, 'include'),
            exclude: self::parsePatterns($rawScope, 'exclude'),
        );

        $entries = [];

        /** @var list<mixed> $rawEntries */
        $rawEntries = $data['entries'];

        foreach ($rawEntries as $index => $rawEntry) {
            if (!is_array($rawEntry)) {
                throw IntegrityException::manifestCorrupted(
                    '(string)',
                    'entry at index ' . $index . ' is not an object',
                );
            }

            /** @var array<string, mixed> $rawEntry */
            if (!isset($rawEntry['path']) || !is_string($rawEntry['path'])) {
                throw IntegrityException::manifestCorrupted(
                    '(string)',
                    'entry at index ' . $index . ' has missing or invalid "path"',
                );
            }

            if (!isset($rawEntry['hash']) || !is_string($rawEntry['hash'])) {
                throw IntegrityException::manifestCorrupted(
                    '(string)',
                    'entry at index ' . $index . ' has missing or invalid "hash"',
                );
            }

            if (!isset($rawEntry['size']) || !is_int($rawEntry['size'])) {
                throw IntegrityException::manifestCorrupted(
                    '(string)',
                    'entry at index ' . $index . ' has missing or invalid "size"',
                );
            }

            $entries[] = new ManifestEntry(
                path: $rawEntry['path'],
                hash: $rawEntry['hash'],
                size: $rawEntry['size'],
            );
        }

        $signature = isset($data['signature']) && is_string($data['signature']) ? $data['signature'] : null;

        return new IntegrityManifest(
            version: $data['version'],
            algorithm: $data['algorithm'],
            generatedAt: $data['generated_at'],
            frameworkVersion: $data['framework_version'],
            entryCount: $data['entry_count'],
            entries: $entries,
            signature: $signature,
            scope: $scope,
        );
    }

    /**
     * Read one glob-pattern list out of the scope object.
     *
     * @param array<string, mixed> $scope
     *
     * @return list<string>
     *
     * @throws IntegrityException If the key is absent or holds anything but strings
     */
    private static function parsePatterns(array $scope, string $key): array
    {
        if (!isset($scope[$key]) || !is_array($scope[$key])) {
            throw IntegrityException::manifestCorrupted(
                '(string)',
                'missing or invalid "scope.' . $key . '" field',
            );
        }

        $patterns = [];

        /** @var array<mixed> $rawPatterns */
        $rawPatterns = $scope[$key];

        foreach ($rawPatterns as $index => $pattern) {
            if (!is_string($pattern)) {
                throw IntegrityException::manifestCorrupted(
                    '(string)',
                    'scope.' . $key . ' entry at index ' . (is_int($index) ? $index : '?') . ' is not a string',
                );
            }

            $patterns[] = $pattern;
        }

        return $patterns;
    }
}
