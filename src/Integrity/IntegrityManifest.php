<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

/**
 * Immutable representation of a file integrity manifest.
 *
 * Contains all tracked file entries with their hashes and metadata
 * about when and how the manifest was generated.
 */
#[Api(since: '1.0.0')]
final readonly class IntegrityManifest
{
    /**
     * @param list<ManifestEntry> $entries Sorted list of file entries
     */
    public function __construct(
        public int $version,
        public string $algorithm,
        public int $generatedAt,
        public string $frameworkVersion,
        public int $entryCount,
        public array $entries,
        public ?string $signature = null,
    ) {}
}
