<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Result of a CMS data import operation.
 *
 * Reports counts of created, updated, and skipped entities per type,
 * along with any warnings or errors encountered during the import.
 */
#[Api(since: '1.0.0')]
final readonly class ImportResult
{
    /**
     * @param array<string, int> $created Counts of created entities keyed by type
     * @param array<string, int> $updated Counts of updated entities keyed by type
     * @param array<string, int> $skipped Counts of skipped entities keyed by type
     * @param list<string> $warnings Non-fatal issues encountered during import
     * @param list<string> $errors Fatal issues that prevented some operations
     * @param bool $dryRun Whether this was a dry-run (no data was persisted)
     */
    public function __construct(
        public array $created,
        public array $updated,
        public array $skipped,
        public array $warnings,
        public array $errors,
        public bool $dryRun,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'dry_run' => $this->dryRun,
        ];
    }
}
