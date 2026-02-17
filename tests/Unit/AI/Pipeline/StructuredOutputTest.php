<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Pipeline\StructuredOutput;
use Pulsar\AI\Pipeline\StructuredOutputResult;

#[CoversClass(StructuredOutput::class)]
#[CoversClass(StructuredOutputResult::class)]
final class StructuredOutputTest extends TestCase
{
    #[Test]
    public function extractReturnsValidParsedData(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('structuredOutput')->willReturn(
            new AiResponse(
                content: '{"name": "John", "age": 30}',
                inputTokens: 50,
                outputTokens: 20,
                finishReason: 'stop',
            ),
        );

        $pipeline = new StructuredOutput($client);

        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];

        $result = $pipeline->extract('Extract person info from: John is 30', $schema);

        self::assertTrue($result->isValid);
        self::assertSame('John', $result->data['name']);
        self::assertSame(30, $result->data['age']);
        self::assertSame('{"name": "John", "age": 30}', $result->rawContent);
    }

    #[Test]
    public function extractReturnsInvalidOnMalformedJson(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('structuredOutput')->willReturn(
            new AiResponse(content: 'not json', inputTokens: 0, outputTokens: 0, finishReason: 'stop'),
        );

        $pipeline = new StructuredOutput($client);
        $result = $pipeline->extract('test', ['type' => 'object']);

        self::assertFalse($result->isValid);
        self::assertSame([], $result->data);
        self::assertSame('not json', $result->rawContent);
    }

    #[Test]
    public function extractReturnsInvalidOnErrorResponse(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('structuredOutput')->willReturn(
            AiResponse::error('API error'),
        );

        $pipeline = new StructuredOutput($client);
        $result = $pipeline->extract('test', ['type' => 'object']);

        self::assertFalse($result->isValid);
    }

    #[Test]
    public function resultGetReturnsDefaultForMissingKey(): void
    {
        $result = new StructuredOutputResult(
            data: ['name' => 'Alice'],
            rawContent: '{"name": "Alice"}',
            isValid: true,
            response: new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop'),
        );

        self::assertSame('Alice', $result->get('name'));
        self::assertNull($result->get('missing'));
        self::assertSame('default', $result->get('missing', 'default'));
    }
}
