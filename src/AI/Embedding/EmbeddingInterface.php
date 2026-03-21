<?php

declare(strict_types=1);

namespace Pulsar\AI\Embedding;

use Pulsar\Api\Api;

/**
 * Interface for generating vector embeddings from text.
 *
 * Implementations connect to embedding APIs (OpenAI, Ollama, etc.)
 * or run local models to produce dense vector representations.
 * @api
 */
#[Api(since: '1.0.0')]
interface EmbeddingInterface
{
    /**
     * Generate embeddings for one or more input texts.
     *
     * @param list<string> $inputs Texts to embed
     * @return EmbeddingResult Embeddings with metadata
     */
    public function embed(array $inputs): EmbeddingResult;

    /**
     * The number of dimensions in the output vectors.
     */
    public function dimensions(): int;

    /**
     * The model used for embedding generation.
     */
    public function model(): string;
}
