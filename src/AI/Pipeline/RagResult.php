<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use Pulsar\AI\AiResponse;
use Pulsar\AI\VectorStore\SearchResult;
use Pulsar\Api\Api;

use function count;

/**
 * Result of a RAG pipeline query.
 *
 * Bundles the AI-generated response with the retrieved context documents
 * for transparency and debugging.
 */
#[Api(since: '1.0.0')]
final readonly class RagResult
{
    /**
     * @param AiResponse $response The LLM-generated answer
     * @param list<SearchResult> $retrievedDocuments Documents used as context
     */
    public function __construct(
        public AiResponse $response,
        public array $retrievedDocuments,
    ) {}

    /**
     * Whether context documents were found and used.
     */
    public function hasContext(): bool
    {
        return $this->retrievedDocuments !== [];
    }

    /**
     * Number of context documents retrieved.
     */
    public function contextCount(): int
    {
        return count($this->retrievedDocuments);
    }
}
