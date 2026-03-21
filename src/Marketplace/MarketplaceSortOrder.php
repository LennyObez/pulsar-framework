<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use Pulsar\Api\Api;

/**
 * Sort orders for marketplace search results.
 * @api
 */
#[Api(since: '1.0.0')]
enum MarketplaceSortOrder: string
{
    case Relevance = 'relevance';
    case Downloads = 'downloads';
    case Rating = 'rating';
    case Newest = 'newest';
    case Name = 'name';
}
