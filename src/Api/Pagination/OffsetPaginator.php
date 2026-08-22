<?php

declare(strict_types=1);

namespace Pulsar\Api\Pagination;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_slice;
use function ceil;
use function count;
use function max;
use function sprintf;

/**
 * Traditional offset/limit pagination.
 *
 * Uses page numbers and per-page sizes to slice datasets. Best for small to
 * medium datasets where total count is available and random page access is needed.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OffsetPaginator implements PaginatorInterface
{
    /**
     * @param list<mixed> $items The full item set (will be sliced)
     * @param int $totalOrEstimate Total count (-1 if unknown)
     * @param PaginationRequest $request Pagination parameters
     * @param string $baseUrl Base URL for link generation
     *
     * @return PaginationResult<mixed>
     */
    #[Override]
    #[NoDiscard]
    public function paginate(
        array $items,
        int $totalOrEstimate,
        PaginationRequest $request,
        string $baseUrl = '',
    ): PaginationResult {
        $page = $request->page;
        $perPage = $request->perPage;
        $offset = $request->offset();

        $total = $totalOrEstimate >= 0 ? $totalOrEstimate : null;
        $lastPage = $total !== null ? max(1, (int) ceil($total / $perPage)) : null;
        $hasMore = $total !== null ? $page < $lastPage : count($items) > $perPage;

        // Slice items for the current page
        $pageItems = array_slice($items, $offset, $perPage);

        // Generate links
        $links = $this->buildLinks($baseUrl, $page, $perPage, $lastPage);

        return new PaginationResult(
            items: $pageItems,
            total: $total,
            hasMore: $hasMore,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
            links: $links,
        );
    }

    private function buildLinks(string $baseUrl, int $page, int $perPage, ?int $lastPage): PaginationLinks
    {
        if ($baseUrl === '') {
            return new PaginationLinks();
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        $first = sprintf('%s%spage=1&per_page=%d', $baseUrl, $separator, $perPage);
        $last = $lastPage !== null ? sprintf('%s%spage=%d&per_page=%d', $baseUrl, $separator, $lastPage, $perPage) : null;
        $next = ($lastPage === null || $page < $lastPage) ? sprintf('%s%spage=%d&per_page=%d', $baseUrl, $separator, $page + 1, $perPage) : null;
        $prev = $page > 1 ? sprintf('%s%spage=%d&per_page=%d', $baseUrl, $separator, $page - 1, $perPage) : null;

        return new PaginationLinks(
            first: $first,
            last: $last,
            next: $next,
            prev: $prev,
        );
    }
}
