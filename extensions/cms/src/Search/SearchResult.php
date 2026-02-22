<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;

/**
 * Full-text search result with ranked items, suggestions, and timing.
 */
#[Api(since: '1.0.0')]
final readonly class SearchResult
{
    /**
     * @param list<Content> $items Ranked content results
     * @param int $total Total matching results across all pages
     * @param string $query Original search query
     * @param list<string> $suggestions Alternative query suggestions
     * @param float $tookMs Time taken in milliseconds
     */
    public function __construct(
        public array $items,
        public int $total,
        public string $query,
        public array $suggestions,
        public float $tookMs,
    ) {}
}
