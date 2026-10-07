<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Pulsar\Api\Api;

/**
 * Decides which tables a query touches, for tag-based cache invalidation.
 *
 * The seam exists because {@see CachedQueryRunner} is `#[Api]` and its correctness
 * depends on this answer: a table the extractor misses is a table whose write never
 * invalidates the entries that read it, and the cache then serves a row that has since
 * changed. An application whose SQL the shipped extractor cannot read — a stored
 * procedure, a dialect-specific construct, a CTE naming its tables somewhere the regular
 * expression does not look — has to be able to supply an answer that is right for its own
 * queries, and pinning the constructor to the final shipped class left it no way to.
 *
 * {@see TableTagExtractor} is the shipped implementation and stays the default, so
 * nothing that does not care has to know this interface exists.
 *
 * An implementation that is unsure must over-report rather than under-report. A tag that
 * names a table the query does not touch costs a redundant invalidation; a missing tag
 * costs a stale read, and in a regulated domain a stale row scoped to one caller can be
 * served to another.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface TableTagExtractorInterface
{
    /**
     * The tables a query references, lowercased and deduplicated.
     *
     * @param array<string, mixed>|null $queryBuilderContext Reserved for query builder integration
     * @return list<string> Lowercase table names
     */
    public function extractTags(string $sql, ?array $queryBuilderContext = null): array;
}
