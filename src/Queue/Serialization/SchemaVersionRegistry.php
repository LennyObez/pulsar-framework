<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Api;

/**
 * Maps job classes to their current schema versions.
 *
 * Used during deserialization to detect version mismatches and trigger
 * payload migration through {@see VersionTransformerInterface} instances.
 * @api
 */
#[Api(since: '1.0.0')]
final class SchemaVersionRegistry
{
    /** @var array<string, int> */
    private array $versions = [];

    /**
     * Register the current schema version for a job class.
     */
    public function register(string $class, int $version): void
    {
        $this->versions[$class] = $version;
    }

    /**
     * Get the current schema version for a job class.
     *
     * Returns 1 for unregistered classes (default schema version).
     */
    public function currentVersion(string $class): int
    {
        return $this->versions[$class] ?? 1;
    }

    /**
     * Validate that a payload's schema version is compatible.
     *
     * A version is valid if it is between 1 and the current registered version
     * (inclusive). Payloads at older versions can be migrated forward;
     * payloads at future versions are rejected.
     */
    public function validate(string $class, int $version): bool
    {
        $current = $this->currentVersion($class);

        return $version >= 1 && $version <= $current;
    }
}
