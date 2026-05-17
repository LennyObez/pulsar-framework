<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use Pulsar\Api\Api;

/**
 * Contract for schema snapshot persistence.
 * @api
 */
#[Api(since: '1.0.0')]
interface SchemaSnapshotStoreInterface
{
    /**
     * Load a snapshot from storage, or null if none exists.
     */
    public function load(): ?SchemaSnapshot;

    /**
     * Save a snapshot to storage.
     */
    public function save(SchemaSnapshot $snapshot): void;

    /**
     * Check if a snapshot exists in storage.
     */
    public function exists(): bool;
}
