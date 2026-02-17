<?php

declare(strict_types=1);

namespace Pulsar\AI\VectorStore;

use Pulsar\Api\Api;

/**
 * Interface for vector similarity search stores.
 *
 * Implementations connect to database-backed vector indexes
 * (pgvector, MySQL 9.0+ VECTOR, sqlite-vec) for nearest-neighbor retrieval.
 */
#[Api(since: '1.0.0')]
interface VectorStoreInterface
{
    /**
     * Search for the nearest vectors to the query vector.
     *
     * @param list<float> $vector Query embedding vector
     * @param int $limit Maximum number of results to return
     * @param array<string, mixed> $filter Optional metadata filters
     * @return list<SearchResult> Ordered by similarity (best first)
     */
    public function search(array $vector, int $limit, array $filter = []): array;

    /**
     * Insert or update a document with its embedding.
     *
     * @param string $id Document identifier
     * @param list<float> $vector Embedding vector
     * @param string $content Original text content
     * @param array<string, mixed> $metadata Optional metadata
     */
    public function upsert(string $id, array $vector, string $content, array $metadata = []): void;

    /**
     * Delete a document by its identifier.
     */
    public function delete(string $id): void;

    /**
     * Delete all documents, optionally filtered by metadata.
     *
     * @param array<string, mixed> $filter Metadata filter; empty = delete all
     */
    public function clear(array $filter = []): void;

    /**
     * Count total documents in the store.
     *
     * @param array<string, mixed> $filter Optional metadata filter
     */
    public function count(array $filter = []): int;
}
