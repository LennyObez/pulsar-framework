<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Pipeline\RagResult;
use Pulsar\AI\VectorStore\SearchResult;

#[CoversClass(RagResult::class)]
final class RagResultTest extends TestCase
{
    #[Test]
    public function hasContextReturnsTrueWhenDocumentsExist(): void
    {
        $docs = [
            new SearchResult(id: 'doc-1', score: 0.95, content: 'Relevant text'),
        ];
        $response = new AiResponse(content: 'Answer', inputTokens: 100, outputTokens: 50, finishReason: 'stop');
        $result = new RagResult(response: $response, retrievedDocuments: $docs);

        self::assertTrue($result->hasContext());
    }

    #[Test]
    public function hasContextReturnsFalseWhenNoDocuments(): void
    {
        $response = new AiResponse(content: 'No context', inputTokens: 10, outputTokens: 5, finishReason: 'stop');
        $result = new RagResult(response: $response, retrievedDocuments: []);

        self::assertFalse($result->hasContext());
    }

    #[Test]
    public function contextCountReturnsDocumentCount(): void
    {
        $docs = [
            new SearchResult(id: 'doc-1', score: 0.95, content: 'First'),
            new SearchResult(id: 'doc-2', score: 0.88, content: 'Second'),
            new SearchResult(id: 'doc-3', score: 0.72, content: 'Third'),
        ];
        $response = new AiResponse(content: 'Answer', inputTokens: 200, outputTokens: 100, finishReason: 'stop');
        $result = new RagResult(response: $response, retrievedDocuments: $docs);

        self::assertSame(3, $result->contextCount());
    }

    #[Test]
    public function contextCountReturnsZeroForEmptyResults(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new RagResult(response: $response, retrievedDocuments: []);

        self::assertSame(0, $result->contextCount());
    }

    #[Test]
    public function responsePropertyIsAccessible(): void
    {
        $response = new AiResponse(content: 'Generated answer', inputTokens: 50, outputTokens: 30, finishReason: 'stop');
        $result = new RagResult(response: $response, retrievedDocuments: []);

        self::assertSame('Generated answer', $result->response->content);
        self::assertTrue($result->response->isComplete());
    }

    #[Test]
    public function retrievedDocumentsPreserveOrder(): void
    {
        $docs = [
            new SearchResult(id: 'best', score: 0.99, content: 'Best match'),
            new SearchResult(id: 'good', score: 0.85, content: 'Good match'),
            new SearchResult(id: 'fair', score: 0.60, content: 'Fair match'),
        ];
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $result = new RagResult(response: $response, retrievedDocuments: $docs);

        self::assertSame('best', $result->retrievedDocuments[0]->id);
        self::assertSame('good', $result->retrievedDocuments[1]->id);
        self::assertSame('fair', $result->retrievedDocuments[2]->id);
    }
}
