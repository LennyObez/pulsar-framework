<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Embedding\EmbeddingInterface;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Embedding\EmbeddingVector;
use Pulsar\AI\Pipeline\RagPipeline;
use Pulsar\AI\Pipeline\RagResult;
use Pulsar\AI\VectorStore\SearchResult;
use Pulsar\AI\VectorStore\VectorStoreInterface;

#[CoversClass(RagPipeline::class)]
#[CoversClass(RagResult::class)]
final class RagPipelineTest extends TestCase
{
    #[Test]
    public function queryRetrievesAndGenerates(): void
    {
        $embedding = new EmbeddingVector(values: [0.1, 0.2, 0.3], index: 0);

        /** @var EmbeddingInterface&Stub $embedder */
        $embedder = $this->createStub(EmbeddingInterface::class);
        $embedder->method('embed')->willReturn(
            new EmbeddingResult(embeddings: [$embedding], totalTokens: 5, model: 'test'),
        );

        $searchResults = [
            new SearchResult(id: 'doc1', score: 0.95, content: 'PHP is a programming language.'),
            new SearchResult(id: 'doc2', score: 0.88, content: 'PHP 8.5 added pipe operator.'),
        ];

        /** @var VectorStoreInterface&Stub $store */
        $store = $this->createStub(VectorStoreInterface::class);
        $store->method('search')->willReturn($searchResults);

        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse(
                content: 'PHP is a programming language that recently added the pipe operator in 8.5.',
                inputTokens: 100,
                outputTokens: 30,
                finishReason: 'stop',
            ),
        );

        $pipeline = new RagPipeline(
            client: $client,
            embedder: $embedder,
            store: $store,
            topK: 5,
        );

        $result = $pipeline->query('What is PHP?');

        self::assertTrue($result->hasContext());
        self::assertSame(2, $result->contextCount());
        self::assertStringContainsString('PHP', $result->response->content);
        self::assertFalse($result->response->isError());
    }

    #[Test]
    public function queryReturnsErrorWhenEmbeddingFails(): void
    {
        /** @var EmbeddingInterface&Stub $embedder */
        $embedder = $this->createStub(EmbeddingInterface::class);
        $embedder->method('embed')->willReturn(
            new EmbeddingResult(embeddings: [], totalTokens: 0, model: 'test'),
        );

        /** @var VectorStoreInterface&Stub $store */
        $store = $this->createStub(VectorStoreInterface::class);

        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);

        $pipeline = new RagPipeline(
            client: $client,
            embedder: $embedder,
            store: $store,
        );

        $result = $pipeline->query('test');

        self::assertTrue($result->response->isError());
        self::assertFalse($result->hasContext());
    }

    #[Test]
    public function queryWithEmptySearchResultsStillGenerates(): void
    {
        $embedding = new EmbeddingVector(values: [0.1], index: 0);

        /** @var EmbeddingInterface&Stub $embedder */
        $embedder = $this->createStub(EmbeddingInterface::class);
        $embedder->method('embed')->willReturn(
            new EmbeddingResult(embeddings: [$embedding], totalTokens: 3, model: 'test'),
        );

        /** @var VectorStoreInterface&Stub $store */
        $store = $this->createStub(VectorStoreInterface::class);
        $store->method('search')->willReturn([]);

        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('complete')->willReturn(
            new AiResponse(content: 'No relevant context found.', inputTokens: 10, outputTokens: 5, finishReason: 'stop'),
        );

        $pipeline = new RagPipeline(
            client: $client,
            embedder: $embedder,
            store: $store,
        );

        $result = $pipeline->query('obscure question');

        self::assertFalse($result->hasContext());
        self::assertSame(0, $result->contextCount());
        self::assertFalse($result->response->isError());
    }

    #[Test]
    public function ragResultHasContextChecksDocuments(): void
    {
        $result = new RagResult(
            response: new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop'),
            retrievedDocuments: [],
        );

        self::assertFalse($result->hasContext());
        self::assertSame(0, $result->contextCount());

        $resultWithDocs = new RagResult(
            response: new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop'),
            retrievedDocuments: [
                new SearchResult(id: 'a', score: 0.9, content: 'test'),
            ],
        );

        self::assertTrue($resultWithDocs->hasContext());
        self::assertSame(1, $resultWithDocs->contextCount());
    }
}
