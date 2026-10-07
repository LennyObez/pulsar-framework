<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use NoDiscard;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingInterface;
use Pulsar\AI\VectorStore\SearchResult;
use Pulsar\AI\VectorStore\VectorStoreInterface;
use Pulsar\Api\Api;

use function array_map;
use function implode;
use function sprintf;

/**
 * Retrieval-Augmented Generation (RAG) pipeline.
 *
 * Combines vector search with LLM generation to produce answers
 * grounded in retrieved context documents.
 *
 * Flow:
 * 1. Embed the user query
 * 2. Search the vector store for relevant documents
 * 3. Build a context-enriched prompt
 * 4. Send to the LLM for generation
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RagPipeline
{
    /**
     * @param AiClientInterface $client LLM for generation
     * @param EmbeddingInterface $embedder Embedding model for query vectorization
     * @param VectorStoreInterface $store Vector store for document retrieval
     * @param int $topK Number of context documents to retrieve
     * @param string $systemPrompt System prompt template; {context} is replaced with retrieved docs
     */
    public function __construct(
        private AiClientInterface $client,
        private EmbeddingInterface $embedder,
        private VectorStoreInterface $store,
        private int $topK = 5,
        private string $systemPrompt = 'Answer the question based on the following context. If the context does not contain enough information, say so.\n\nContext:\n{context}',
    ) {}

    /**
     * Run the RAG pipeline: embed, retrieve, generate.
     *
     * @param array<string, mixed> $filter Optional metadata filter for vector search
     */
    #[NoDiscard]
    public function query(string $question, array $filter = [], AiRequestOptions $options = new AiRequestOptions()): RagResult
    {
        // Step 1: Embed the query
        $embeddingResult = $this->embedder->embed([$question]);
        $queryVector = $embeddingResult->first();

        if ($queryVector === null) {
            return new RagResult(
                response: AiResponse::error('Failed to generate query embedding'),
                retrievedDocuments: [],
            );
        }

        // Step 2: Search vector store
        $searchResults = $this->store->search($queryVector->values, $this->topK, $filter);

        // Step 3: Build context from retrieved documents
        $contextParts = array_map(
            static fn(SearchResult $r): string => sprintf(
                "[%s] (score: %.4f)\n%s",
                $r->id,
                $r->score,
                $r->content,
            ),
            $searchResults,
        );

        $context = implode("\n\n---\n\n", $contextParts);

        // Step 4: Generate with enriched context
        $systemPrompt = str_replace('{context}', $context, $this->systemPrompt);

        $response = $this->client->complete(
            $question,
            new AiRequestOptions(
                temperature: $options->temperature,
                maxTokens: $options->maxTokens,
                systemPrompt: $systemPrompt,
                model: $options->model,
                timeoutSeconds: $options->timeoutSeconds,
            ),
        );

        return new RagResult(
            response: $response,
            retrievedDocuments: $searchResults,
        );
    }
}
