<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use Pulsar\Api\Api;
use Pulsar\Extensibility\TrustTier;

/**
 * Filters for marketplace search queries.
 */
#[Api(since: '1.0.0')]
final readonly class MarketplaceSearchFilter
{
    /**
     * @param TrustTier|null $trustTier Filter by trust tier
     * @param string|null $category Filter by category
     * @param string|null $pulsarVersion Filter by Pulsar version compatibility
     * @param MarketplaceSortOrder $sortBy Sort order
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     */
    public function __construct(
        public ?TrustTier $trustTier = null,
        public ?string $category = null,
        public ?string $pulsarVersion = null,
        public MarketplaceSortOrder $sortBy = MarketplaceSortOrder::Relevance,
        public int $limit = 50,
        public int $offset = 0,
    ) {}
}
