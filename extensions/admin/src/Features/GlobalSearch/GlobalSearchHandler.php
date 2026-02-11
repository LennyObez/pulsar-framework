<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\GlobalSearch;

use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;

use function count;

/**
 * Handles searching across all registered admin resources.
 */
final readonly class GlobalSearchHandler
{
    public function __construct(
        private ResourceRegistryInterface $registry,
        private ResourceQueryInterface $query,
        private FieldVisibilityFilter $visibilityFilter,
    ) {}

    public function execute(GlobalSearchRequest $request): GlobalSearchResult
    {
        if ($request->query === '') {
            return new GlobalSearchResult(results: [], totalMatches: 0);
        }

        $results = [];
        $totalMatches = 0;

        foreach ($this->registry->all() as $name => $resource) {
            $matches = $this->query->search($resource, $request->query, $request->limitPerResource);

            if ($matches === []) {
                continue;
            }

            $filtered = array_map(
                fn(array $row): array => $this->visibilityFilter->filterForList($resource, $row),
                $matches,
            );

            $results[$name] = $filtered;
            $totalMatches += count($filtered);
        }

        return new GlobalSearchResult(
            results: $results,
            totalMatches: $totalMatches,
        );
    }
}
