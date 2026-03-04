<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ToolCall;

#[CoversClass(AiResponse::class)]
#[CoversClass(ToolCall::class)]
final class AiResponseTest extends TestCase
{
    #[Test]
    public function totalTokensSumsInputAndOutput(): void
    {
        $response = new AiResponse(
            content: 'Hello',
            inputTokens: 100,
            outputTokens: 50,
            finishReason: 'stop',
        );

        self::assertSame(150, $response->totalTokens());
    }

    #[Test]
    public function isCompleteReturnsTrueForStopReason(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');

        self::assertTrue($response->isComplete());
    }

    #[Test]
    public function isCompleteReturnsFalseForLengthReason(): void
    {
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'length');

        self::assertFalse($response->isComplete());
    }

    #[Test]
    public function hasToolCallsDetectsPresence(): void
    {
        $withoutTools = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');

        self::assertFalse($withoutTools->hasToolCalls());

        $withTools = new AiResponse(
            content: '',
            inputTokens: 0,
            outputTokens: 0,
            finishReason: 'tool_use',
            toolCalls: [new ToolCall('tc_1', 'get_weather', ['city' => 'Paris'])],
        );

        self::assertTrue($withTools->hasToolCalls());
    }

    #[Test]
    public function isErrorDetectsErrorFinishReason(): void
    {
        $ok = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
        $error = new AiResponse(content: 'fail', inputTokens: 0, outputTokens: 0, finishReason: 'error');

        self::assertFalse($ok->isError());
        self::assertTrue($error->isError());
    }

    #[Test]
    public function fromArrayParsesFullPayload(): void
    {
        $data = [
            'content' => 'Result text',
            'input_tokens' => 200,
            'output_tokens' => 80,
            'finish_reason' => 'stop',
            'model' => 'gpt-4o',
            'tool_calls' => [
                ['id' => 'tc_1', 'name' => 'search', 'arguments' => ['q' => 'test']],
            ],
        ];

        $response = AiResponse::fromArray($data);

        self::assertSame('Result text', $response->content);
        self::assertSame(200, $response->inputTokens);
        self::assertSame(80, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
        self::assertSame('gpt-4o', $response->model);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('search', $response->toolCalls[0]->name);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $response = AiResponse::fromArray([]);

        self::assertSame('', $response->content);
        self::assertSame(0, $response->inputTokens);
        self::assertSame(0, $response->outputTokens);
        self::assertSame('stop', $response->finishReason);
        self::assertSame('', $response->model);
        self::assertSame([], $response->toolCalls);
    }

    #[Test]
    public function errorFactoryCreatesErrorResponse(): void
    {
        $response = AiResponse::error('Connection failed');

        self::assertSame('Connection failed', $response->content);
        self::assertTrue($response->isError());
        self::assertSame(0, $response->inputTokens);
        self::assertSame(0, $response->outputTokens);
    }

    #[Test]
    public function modelPropertyIsPreserved(): void
    {
        $response = new AiResponse(
            content: 'test',
            inputTokens: 0,
            outputTokens: 0,
            finishReason: 'stop',
            model: 'claude-sonnet-4-6',
        );

        self::assertSame('claude-sonnet-4-6', $response->model);
    }

    #[Test]
    public function toolCallFromArrayHandlesMissingFields(): void
    {
        $tc = ToolCall::fromArray([]);

        self::assertSame('', $tc->id);
        self::assertSame('', $tc->name);
        self::assertSame([], $tc->arguments);
    }

    #[Test]
    public function toolCallFromArrayParsesFullData(): void
    {
        $tc = ToolCall::fromArray([
            'id' => 'call_123',
            'name' => 'get_weather',
            'arguments' => ['location' => 'London', 'units' => 'celsius'],
        ]);

        self::assertSame('call_123', $tc->id);
        self::assertSame('get_weather', $tc->name);
        self::assertSame('London', $tc->arguments['location']);
        self::assertSame('celsius', $tc->arguments['units']);
    }
}
