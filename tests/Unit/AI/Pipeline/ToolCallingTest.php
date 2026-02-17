<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\AiResponse;
use Pulsar\AI\ChatMessage;
use Pulsar\AI\Pipeline\ToolCallExecution;
use Pulsar\AI\Pipeline\ToolCalling;
use Pulsar\AI\Pipeline\ToolCallingResult;
use Pulsar\AI\ToolCall;
use Pulsar\AI\ToolDefinition;
use RuntimeException;

#[CoversClass(ToolCalling::class)]
#[CoversClass(ToolCallingResult::class)]
#[CoversClass(ToolCallExecution::class)]
final class ToolCallingTest extends TestCase
{
    #[Test]
    public function runReturnsDirectResponseWhenNoToolCalls(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('chat')->willReturn(
            new AiResponse(content: 'Hello!', inputTokens: 10, outputTokens: 5, finishReason: 'stop'),
        );

        $pipeline = new ToolCalling($client);
        $result = $pipeline->run([ChatMessage::user('Hi')]);

        self::assertSame('Hello!', $result->response->content);
        self::assertSame([], $result->executions);
        self::assertSame(1, $result->iterations);
        self::assertTrue($result->allSucceeded());
    }

    #[Test]
    public function runExecutesToolCallAndContinues(): void
    {
        $callCount = 0;

        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('chat')->willReturnCallback(
            function () use (&$callCount): AiResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new AiResponse(
                        content: '',
                        inputTokens: 10,
                        outputTokens: 5,
                        finishReason: 'tool_use',
                        toolCalls: [new ToolCall('tc_1', 'get_time', [])],
                    );
                }

                return new AiResponse(
                    content: 'The time is 3:00 PM',
                    inputTokens: 20,
                    outputTokens: 10,
                    finishReason: 'stop',
                );
            },
        );

        $pipeline = new ToolCalling($client);
        $pipeline->register(
            new ToolDefinition('get_time', 'Get current time', []),
            static fn(array $args): string => '15:00',
        );

        $result = $pipeline->run([ChatMessage::user('What time is it?')]);

        self::assertSame('The time is 3:00 PM', $result->response->content);
        self::assertCount(1, $result->executions);
        self::assertTrue($result->executions[0]->succeeded);
        self::assertSame('15:00', $result->executions[0]->output);
        self::assertSame(2, $result->iterations);
    }

    #[Test]
    public function runHandlesUnknownToolGracefully(): void
    {
        $callCount = 0;

        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('chat')->willReturnCallback(
            function () use (&$callCount): AiResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new AiResponse(
                        content: '',
                        inputTokens: 10,
                        outputTokens: 5,
                        finishReason: 'tool_use',
                        toolCalls: [new ToolCall('tc_1', 'unknown_tool', [])],
                    );
                }

                return new AiResponse(
                    content: 'Done',
                    inputTokens: 0,
                    outputTokens: 0,
                    finishReason: 'stop',
                );
            },
        );

        $pipeline = new ToolCalling($client);
        $result = $pipeline->run([ChatMessage::user('test')]);

        self::assertCount(1, $result->executions);
        self::assertFalse($result->executions[0]->succeeded);
        self::assertStringContainsString('Unknown tool', $result->executions[0]->output);
        self::assertFalse($result->allSucceeded());
    }

    #[Test]
    public function runRespectsMaxIterations(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('chat')->willReturn(
            new AiResponse(
                content: '',
                inputTokens: 0,
                outputTokens: 0,
                finishReason: 'tool_use',
                toolCalls: [new ToolCall('tc_1', 'loop', [])],
            ),
        );

        $pipeline = new ToolCalling($client, maxIterations: 3);
        $pipeline->register(
            new ToolDefinition('loop', 'Infinite loop tool', []),
            static fn(array $args): string => 'again',
        );

        $result = $pipeline->run([ChatMessage::user('loop forever')]);

        self::assertTrue($result->response->isError());
        self::assertStringContainsString('max iterations', $result->response->content);
        self::assertSame(3, $result->iterations);
    }

    #[Test]
    public function runHandlesToolExecutionException(): void
    {
        $callCount = 0;

        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('chat')->willReturnCallback(
            function () use (&$callCount): AiResponse {
                $callCount++;

                if ($callCount === 1) {
                    return new AiResponse(
                        content: '',
                        inputTokens: 0,
                        outputTokens: 0,
                        finishReason: 'tool_use',
                        toolCalls: [new ToolCall('tc_1', 'failing', [])],
                    );
                }

                return new AiResponse(content: 'Recovered', inputTokens: 0, outputTokens: 0, finishReason: 'stop');
            },
        );

        $pipeline = new ToolCalling($client);
        $pipeline->register(
            new ToolDefinition('failing', 'Always fails', []),
            static fn(array $args): string => throw new RuntimeException('Tool broke'),
        );

        $result = $pipeline->run([ChatMessage::user('test')]);

        self::assertCount(1, $result->executions);
        self::assertFalse($result->executions[0]->succeeded);
        self::assertStringContainsString('Tool broke', $result->executions[0]->output);
    }

    #[Test]
    public function runReturnsErrorResponseOnApiError(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);
        $client->method('chat')->willReturn(AiResponse::error('API down'));

        $pipeline = new ToolCalling($client);
        $result = $pipeline->run([ChatMessage::user('test')]);

        self::assertTrue($result->response->isError());
        self::assertSame(1, $result->iterations);
    }

    #[Test]
    public function registerIsFluent(): void
    {
        /** @var AiClientInterface&Stub $client */
        $client = $this->createStub(AiClientInterface::class);

        $pipeline = new ToolCalling($client);

        $returned = $pipeline->register(
            new ToolDefinition('test', 'test', []),
            static fn(array $args): string => 'ok',
        );

        self::assertSame($pipeline, $returned);
    }
}
