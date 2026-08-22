<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

/**
 * Immutable representation of a file integrity manifest.
 *
 * Contains all tracked file entries with their hashes, the scope they were
 * drawn from, and metadata about when and how the manifest was generated.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IntegrityManifest
{
    /**
     * Schema version this build produces and accepts.
     *
     * Version 2 added the scope. Version 1 carried entries alone, which left
     * the verifier guessing which files were meant to be on disk.
     */
    public const int VERSION = 2;

    /**
     * @param list<ManifestEntry> $entries Sorted list of file entries
     * @param ManifestScope|null $scope The patterns the entries were drawn from.
     *        Null only for manifests assembled in code; anything parsed from
     *        JSON carries one, and verifying a scopeless manifest fails closed.
     */
    public function __construct(
        public int $version,
        public string $algorithm,
        public int $generatedAt,
        public string $frameworkVersion,
        public int $entryCount,
        public array $entries,
        public ?string $signature = null,
        public ?ManifestScope $scope = null,
    ) {}
}
