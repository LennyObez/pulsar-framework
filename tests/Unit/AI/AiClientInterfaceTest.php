<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;

/**
 * Tests the AiClientInterface contract via stubs.
 */
final class AiClientInterfaceTest extends TestCase
{
    #[Test]
    public function chatReturnsAiResponse(): void
    {
        // Arrange
        $response = new AiResponse('Hello!', 10, 5, 'stop');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('chat')->willReturn($response);

        // Act
        $result = $stub->chat([ChatMessage::user('Hi')]);

        // Assert
        self::assertSame('Hello!', $result->content);
        self::assertSame('stop', $result->finishReason);
        self::assertSame(15, $result->totalTokens());
    }

    #[Test]
    public function chatAcceptsCustomOptions(): void
    {
        // Arrange
        $response = new AiResponse('Response', 20, 10, 'stop', [], 'gpt-4');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('chat')->willReturn($response);

        $options = new AiRequestOptions(temperature: 0.5, maxTokens: 100);

        // Act
        $result = $stub->chat([ChatMessage::system('Be brief'), ChatMessage::user('Hello')], $options);

        // Assert
        self::assertSame('gpt-4', $result->model);
        self::assertSame(30, $result->totalTokens());
    }

    #[Test]
    public function completeReturnsSinglePromptResponse(): void
    {
        // Arrange
        $response = new AiResponse('Completed text', 8, 12, 'stop');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('complete')->willReturn($response);

        // Act
        $result = $stub->complete('Write a haiku');

        // Assert
        self::assertSame('Completed text', $result->content);
        self::assertTrue($result->isComplete());
    }

    #[Test]
    public function embedReturnsEmbeddingResult(): void
    {
        // Arrange
        $embeddingResult = new EmbeddingResult([], 50, 'text-embedding-3');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('embed')->willReturn($embeddingResult);

        // Act
        $result = $stub->embed(['Hello world', 'Test input']);

        // Assert
        self::assertSame(50, $result->totalTokens);
        self::assertSame('text-embedding-3', $result->model);
        self::assertSame(0, $result->count());
    }

    #[Test]
    public function structuredOutputReturnsConstrainedResponse(): void
    {
        // Arrange
        $response = new AiResponse('{"name":"John","age":30}', 15, 8, 'stop');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('structuredOutput')->willReturn($response);

        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];

        // Act
        $result = $stub->structuredOutput('Extract name and age from: John is 30', $schema);

        // Assert
        self::assertStringContainsString('John', $result->content);
        self::assertTrue($result->isComplete());
        self::assertFalse($result->isError());
    }

    #[Test]
    public function providerNameReturnsIdentifier(): void
    {
        // Arrange
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('providerName')->willReturn('anthropic');

        // Act & Assert
        self::assertSame('anthropic', $stub->providerName());
    }

    #[Test]
    public function chatHandlesErrorResponse(): void
    {
        // Arrange
        $errorResponse = AiResponse::error('Rate limit exceeded');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('chat')->willReturn($errorResponse);

        // Act
        $result = $stub->chat([ChatMessage::user('test')]);

        // Assert
        self::assertTrue($result->isError());
        self::assertFalse($result->isComplete());
        self::assertSame('Rate limit exceeded', $result->content);
    }

    #[Test]
    public function completeWithDefaultOptionsDoesNotThrow(): void
    {
        // Arrange
        $response = new AiResponse('result', 5, 3, 'stop');
        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('complete')->willReturn($response);

        // Act
        $result = $stub->complete('prompt', new AiRequestOptions());

        // Assert
        self::assertSame('result', $result->content);
    }
}
