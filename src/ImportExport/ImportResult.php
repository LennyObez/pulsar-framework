<?php

declare(strict_types=1);

namespace Pulsar\ImportExport;

use Pulsar\Api\Api;

/**
 * Result of an import operation from a single provider.
 */
#[Api(since: '1.0.0')]
final readonly class ImportResult
{
    /**
     * @param string $providerName Provider that processed this import
     * @param array<string, int> $created Counts of created entities keyed by type
     * @param array<string, int> $updated Counts of updated entities keyed by type
     * @param array<string, int> $skipped Counts of skipped entities keyed by type
     * @param list<string> $warnings Non-fatal issues encountered during import
     * @param list<string> $errors Fatal issues that prevented some operations
     * @param bool $dryRun Whether this was a dry-run (no data persisted)
     */
    public function __construct(
        public string $providerName,
        public array $created,
        public array $updated,
        public array $skipped,
        public array $warnings,
        public array $errors,
        public bool $dryRun,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function totalCreated(): int
    {
        return (int) array_sum($this->created);
    }

    public function totalUpdated(): int
    {
        return (int) array_sum($this->updated);
    }

    public function totalSkipped(): int
    {
        return (int) array_sum($this->skipped);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerName,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'dry_run' => $this->dryRun,
        ];
    }
}
