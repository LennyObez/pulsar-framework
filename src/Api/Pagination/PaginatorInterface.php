<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Common contract for all pagination strategies.
 *
 * Paginators take a query-building callback (or pre-fetched items) and return
 * a {@see PaginationResult} with consistent metadata regardless of strategy.
 */
#[Api(since: '1.0.0')]
interface PaginatorInterface
{
    /**
     * Paginate a dataset.
     *
     * @param list<mixed> $items The full (or already-sliced) item set
     * @param int $totalOrEstimate Total count or estimate (-1 if unknown)
     * @param PaginationRequest $request The pagination parameters from the client
     * @param string $baseUrl Base URL for generating pagination links
     *
     * @return PaginationResult<mixed>
     */
    #[NoDiscard]
    public function paginate(
        array $items,
        int $totalOrEstimate,
        PaginationRequest $request,
        string $baseUrl = '',
    ): PaginationResult;
}
