<?php

declare(strict_types=1);

namespace Pulsar\Marketplace\Internal;

use Pulsar\Api\Internal;
use Pulsar\Marketplace\ExtensionListing;
use Pulsar\Marketplace\MarketplaceRegistryInterface;
use Pulsar\Marketplace\MarketplaceSearchFilter;
use Pulsar\Marketplace\MarketplaceSortOrder;

use function array_slice;
use function in_array;
use function str_contains;
use function strtolower;
use function usort;

/**
 * In-memory marketplace registry for testing and local development.
 */
#[Internal]
final class InMemoryMarketplaceRegistry implements MarketplaceRegistryInterface
{
    /** @var array<string, ExtensionListing> Name → listing */
    private array $listings = [];

    /** @var array<string, list<string>> Name → available versions */
    private array $versionMap = [];

    /**
     * Register a listing in the registry.
     *
     * @param list<string> $availableVersions
     */
    public function register(ExtensionListing $listing, array $availableVersions = []): void
    {
        $this->listings[$listing->name] = $listing;
        $this->versionMap[$listing->name] = $availableVersions !== []
            ? $availableVersions
            : [$listing->version];
    }

    public function search(string $query, ?MarketplaceSearchFilter $filter = null): array
    {
        $results = [];
        $queryLower = strtolower($query);

        foreach ($this->listings as $listing) {
            if (!$this->matchesQuery($listing, $queryLower)) {
                continue;
            }

            if ($filter !== null && !$this->matchesFilter($listing, $filter)) {
                continue;
            }

            $results[] = $listing;
        }

        $sortBy = $filter->sortBy ?? MarketplaceSortOrder::Relevance;
        $this->sortResults($results, $sortBy);

        $offset = $filter->offset ?? 0;
        $limit = $filter->limit ?? 50;

        return array_slice($results, $offset, $limit);
    }

    public function find(string $name): ?ExtensionListing
    {
        return $this->listings[$name] ?? null;
    }

    public function versions(string $name): array
    {
        return $this->versionMap[$name] ?? [];
    }

    public function exists(string $name): bool
    {
        return isset($this->listings[$name]);
    }

    private function matchesQuery(ExtensionListing $listing, string $query): bool
    {
        if ($query === '') {
            return true;
        }

        return str_contains(strtolower($listing->name), $query)
            || str_contains(strtolower($listing->description), $query)
            || str_contains(strtolower($listing->author), $query);
    }

    private function matchesFilter(ExtensionListing $listing, MarketplaceSearchFilter $filter): bool
    {
        if ($filter->trustTier !== null && $listing->trustTier !== $filter->trustTier) {
            return false;
        }

        if ($filter->category !== null && !in_array($filter->category, $listing->categories, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<ExtensionListing> $results
     */
    private function sortResults(array &$results, MarketplaceSortOrder $sortBy): void
    {
        usort($results, static fn(ExtensionListing $a, ExtensionListing $b): int => match ($sortBy) {
            MarketplaceSortOrder::Downloads => $b->downloads <=> $a->downloads,
            MarketplaceSortOrder::Rating => $b->rating <=> $a->rating,
            MarketplaceSortOrder::Name => $a->name <=> $b->name,
            MarketplaceSortOrder::Newest => $b->version <=> $a->version,
            default => 0,
        });
    }
}
