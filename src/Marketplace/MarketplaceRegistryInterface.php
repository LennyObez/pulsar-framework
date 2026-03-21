<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use Pulsar\Api\Api;

/**
 * Contract for the extension marketplace registry.
 * @api
 */
#[Api(since: '1.0.0')]
interface MarketplaceRegistryInterface
{
    /**
     * Search for extensions by keyword.
     *
     * @param string $query Search query
     * @param MarketplaceSearchFilter|null $filter Optional filters
     * @return list<ExtensionListing>
     */
    public function search(string $query, ?MarketplaceSearchFilter $filter = null): array;

    /**
     * Get a specific extension listing by name.
     */
    public function find(string $name): ?ExtensionListing;

    /**
     * Get all available versions for an extension.
     *
     * @return list<string> Version strings sorted newest first
     */
    public function versions(string $name): array;

    /**
     * Check if an extension exists in the registry.
     */
    public function exists(string $name): bool;
}
