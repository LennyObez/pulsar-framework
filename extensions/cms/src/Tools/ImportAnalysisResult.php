<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Result of analyzing an import bundle before executing it.
 *
 * Provides entity counts, duplicate detection, and dependency analysis
 * so the user can review before committing the import.
 */
#[Api(since: '1.0.0')]
final readonly class ImportAnalysisResult
{
    /**
     * @param int $totalEntities Total number of entities found in the bundle
     * @param array<string, int> $duplicatesByType Number of duplicates found per entity type
     * @param list<string> $missingDependencies References to entities not found in the target system
     * @param array<string, int> $entityCounts Number of entities per type in the bundle
     */
    public function __construct(
        public int $totalEntities,
        public array $duplicatesByType,
        public array $missingDependencies,
        public array $entityCounts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_entities' => $this->totalEntities,
            'duplicates_by_type' => $this->duplicatesByType,
            'missing_dependencies' => $this->missingDependencies,
            'entity_counts' => $this->entityCounts,
        ];
    }
}
