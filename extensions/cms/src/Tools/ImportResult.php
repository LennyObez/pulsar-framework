<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

use function array_sum;

/**
 * Result of a CMS data import operation.
 *
 * Reports counts of created, updated, and skipped entities per type,
 * along with any warnings or errors encountered during the import.
 *
 * @psalm-api Public DTO returned from ImportExportServiceInterface::import().
 * @api
 */
#[Api(since: '1.0.0')]
readonly class ImportResult
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

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Merge another ImportResult into this one, combining counts and messages.
     *
     * Used when importing multiple files or delegating to extension providers.
     */
    public function merge(self $other): self
    {
        return new self(
            created: self::mergeCountMaps($this->created, $other->created),
            updated: self::mergeCountMaps($this->updated, $other->updated),
            skipped: self::mergeCountMaps($this->skipped, $other->skipped),
            warnings: [...$this->warnings, ...$other->warnings],
            errors: [...$this->errors, ...$other->errors],
            dryRun: $this->dryRun && $other->dryRun,
        );
    }

    /**
     * Merge two count maps, summing values for shared keys.
     *
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    private static function mergeCountMaps(array $a, array $b): array
    {
        $result = $a;

        foreach ($b as $key => $value) {
            $result[$key] = ($result[$key] ?? 0) + $value;
        }

        return $result;
    }

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
