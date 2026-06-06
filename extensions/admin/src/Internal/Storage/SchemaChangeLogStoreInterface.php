<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Pulsar\Api\Internal;

/**
 * Persistence contract for schema change log entries.
 */
#[Internal]
interface SchemaChangeLogStoreInterface
{
    public function record(SchemaChangeLogEntry $entry): void;

    /**
     * @return list<SchemaChangeLogEntry>
     */
    public function recent(int $limit = 100): array;

    /**
     * @return list<SchemaChangeLogEntry>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function forTable(string $table, int $limit = 50): array;

    /**
     * Export all entries as a SQL bundle with evidence hashes.
     */
    public function exportSqlBundle(): string;
}
