<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Detailed report of a completed import operation.
 *
 * Provides aggregate counts and per-entity-type breakdown
 * for monitoring and user feedback.
 *
 * @psalm-api Public DTO returned from MediaBundleImporter::import(); consumed
 *            by the import-result view and audit logging.
 */
#[Api(since: '1.0.0')]
final readonly class ImportReport
{
    /**
     * @param int $created Total entities created
     * @param int $updated Total entities updated (replaced/merged)
     * @param int $skipped Total entities skipped (duplicates with Skip policy)
     * @param int $failed Total entities that failed to import
     * @param list<string> $errors Error messages for failed entities
     * @param array<string, array{created: int, updated: int, skipped: int, failed: int}> $entityBreakdown Per-type breakdown
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
        public array $errors,
        public array $entityBreakdown,
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
            'failed' => $this->failed,
            'errors' => $this->errors,
            'entity_breakdown' => $this->entityBreakdown,
        ];
    }
}
