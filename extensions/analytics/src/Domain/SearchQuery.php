<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * An internal site search query with result metrics.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SearchQuery
{
    /**
     * @param string $query The search term
     * @param int $count Number of times this query was searched
     * @param int $resultCount Average number of results returned
     * @param float $clickThroughRate Percentage of searches that led to a click
     */
    public function __construct(
        public string $query,
        public int $count,
        public int $resultCount = 0,
        public float $clickThroughRate = 0.0,
    ) {}
}
