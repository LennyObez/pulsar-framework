<?php

declare(strict_types=1);

namespace Pulsar\AI\Embedding;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * Result of an embedding generation request.
 *
 * Contains one or more embedding vectors alongside usage metadata.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EmbeddingResult
{
    /**
     * @param list<EmbeddingVector> $embeddings Generated embedding vectors
     * @param int $totalTokens Total tokens consumed
     * @param string $model The embedding model used
     */
    public function __construct(
        public array $embeddings,
        public int $totalTokens,
        public string $model,
    ) {}

    /**
     * Get the first embedding vector, or null if empty.
     */
    #[NoDiscard]
    public function first(): ?EmbeddingVector
    {
        return $this->embeddings[0] ?? null;
    }

    /**
     * Number of embeddings in the result.
     */
    public function count(): int
    {
        return count($this->embeddings);
    }
}
