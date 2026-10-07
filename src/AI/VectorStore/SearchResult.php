<?php

declare(strict_types=1);

namespace Pulsar\AI\VectorStore;

use Pulsar\Api\Api;

/**
 * A single vector search result.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SearchResult
{
    /**
     * @param string $id Document identifier
     * @param float $score Similarity score (higher = more similar for cosine/inner product; lower = more similar for L2)
     * @param string $content Original text content
     * @param array<string, mixed> $metadata Associated metadata
     */
    public function __construct(
        public string $id,
        public float $score,
        public string $content,
        public array $metadata = [],
    ) {}
}
