<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\GlobalSearch;

/**
 * Result DTO for global search.
 */
final readonly class GlobalSearchResult
{
    /**
     * @param array<string, list<array<string, mixed>>> $results Keyed by resource name
     */
    public function __construct(
        public array $results,
        public int $totalMatches,
    ) {}
}
