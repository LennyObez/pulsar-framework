<?php

declare(strict_types=1);

namespace Pulsar\Build;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * The build manifest tracks all compiled artifacts and their content hashes.
 *
 * Provides integrity verification by recording SHA-256 hashes and sizes
 * for every artifact, plus content hashes for source inputs. An optional
 * HMAC signature seals the manifest for tamper detection.
 */
#[Api(since: '1.0.0')]
final readonly class BuildManifest
{
    /**
     * @param int $version Manifest schema version
     * @param string $algorithm Hash algorithm used (always 'sha256')
     * @param array<string, ArtifactEntry> $artifacts Artifact key to entry mapping
     * @param array<string, string> $contentHashes Source content hash keys to SHA-256 values
     * @param ?string $signature Optional HMAC signature
     */
    public function __construct(
        public int $version,
        public string $algorithm,
        public array $artifacts,
        public array $contentHashes,
        public ?string $signature = null,
    ) {}

    /**
     * Create from array data.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, array<string, mixed>> $artifactData */
        $artifactData = is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [];

        $artifacts = array_map(
            static fn(array $entry): ArtifactEntry => ArtifactEntry::fromArray($entry),
            $artifactData,
        );

        ksort($artifacts, SORT_STRING);

        /** @var array<string, string> $contentHashes */
        $contentHashes = is_array($data['contentHashes'] ?? null) ? $data['contentHashes'] : [];

        ksort($contentHashes, SORT_STRING);

        return new self(
            version: isset($data['version']) && is_int($data['version']) ? $data['version'] : 1,
            algorithm: is_string($data['algorithm'] ?? null) ? $data['algorithm'] : 'sha256',
            artifacts: $artifacts,
            contentHashes: $contentHashes,
            signature: is_string($data['signature'] ?? null) ? $data['signature'] : null,
        );
    }

    /**
     * Export to array representation.
     *
     * @return array{version: int, algorithm: string, artifacts: array<string, array{path: string, hash: string, size: int}>, contentHashes: array<string, string>, signature: ?string}
     */
    public function toArray(): array
    {
        $artifacts = array_map(
            static fn(ArtifactEntry $entry): array => $entry->toArray(),
            $this->artifacts,
        );

        ksort($artifacts, SORT_STRING);

        $contentHashes = $this->contentHashes;
        ksort($contentHashes, SORT_STRING);

        return [
            'version' => $this->version,
            'algorithm' => $this->algorithm,
            'artifacts' => $artifacts,
            'contentHashes' => $contentHashes,
            'signature' => $this->signature,
        ];
    }

    /**
     * Create from a JSON string.
     *
     * @throws JsonException On invalid JSON
     */
    #[NoDiscard]
    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * Export as deterministic JSON.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
