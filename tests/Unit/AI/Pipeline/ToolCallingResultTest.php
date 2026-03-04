<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiResponse;
use Pulsar\AI\Pipeline\ToolCallExecution;
use Pulsar\AI\Pipeline\ToolCallingResult;
use Pulsar\AI\ToolCall;

#[CoversClass(ToolCallingResult::class)]
final class ToolCallingResultTest extends TestCase
{
    #[Test]
    public function allSucceededReturnsTrueWhenAllExecutionsSucceed(): void
    {
        $exec1 = new ToolCallExecution(
            toolCall: new ToolCall('tc_1', 'search', []),
            output: 'found',
            succeeded: true,
        );
        $exec2 = new ToolCallExecution(
            toolCall: new ToolCall('tc_2', 'fetch', []),
            output: 'fetched',
            succeeded: true,
        );
        $response = new AiResponse(content: 'Done', inputTokens: 50, outputTokens: 20, finishReason: 'stop');

        $result = new ToolCallingResult(response: $response, executions: [$exec1, $exec2], iterations: 2);

        self::assertTrue($result->allSucceeded());
    }

    #[Test]
    public function allSucceededReturnsFalseWhenAnyExecutionFails(): void
    {
        $success = new ToolCallExecution(
            toolCall: new ToolCall('tc_1', 'search', []),
            output: 'found',
            succeeded: true,
        );
        $failure = new ToolCallExecution(
            toolCall: new ToolCall('tc_2', 'delete', []),
            output: 'Error: permission denied',
            succeeded: false,
        );
        $response = new AiResponse(content: 'Partial', inputTokens: 30, outputTokens: 10, finishReason: 'stop');

        $result = new ToolCallingResult(response: $response, executions: [$success, $failure], iterations: 1);

        self::assertFalse($result->allSucceeded());
    }

    #[Test]
    public function allSucceededReturnsTrueForEmptyExecutions(): void
    {
        $response = new AiResponse(content: 'Direct answer', inputTokens: 10, outputTokens: 5, finishReason: 'stop');

        $result = new ToolCallingResult(response: $response, executions: [], iterations: 1);

        self::assertTrue($result->allSucceeded());
    }

    #[Test]
    public function allSucceededReturnsFalseWhenOnlyExecutionFails(): void
    {
        $failure = new ToolCallExecution(
            toolCall: new ToolCall('tc_1', 'broken_tool', []),
            output: 'Error: timeout',
            succeeded: false,
        );
        $response = new AiResponse(content: '', inputTokens: 0, outputTokens: 0, finishReason: 'error');

        $result = new ToolCallingResult(response: $response, executions: [$failure], iterations: 1);

        self::assertFalse($result->allSucceeded());
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $response = new AiResponse(content: 'Final answer', inputTokens: 200, outputTokens: 100, finishReason: 'stop');

        $result = new ToolCallingResult(response: $response, executions: [], iterations: 3);

        self::assertSame('Final answer', $result->response->content);
        self::assertSame([], $result->executions);
        self::assertSame(3, $result->iterations);
    }

    #[Test]
    public function multipleFailuresAllReportedCorrectly(): void
    {
        $fail1 = new ToolCallExecution(
            toolCall: new ToolCall('tc_1', 'tool_a', []),
            output: 'Error A',
            succeeded: false,
        );
        $fail2 = new ToolCallExecution(
            toolCall: new ToolCall('tc_2', 'tool_b', []),
            output: 'Error B',
            succeeded: false,
        );
        $response = AiResponse::error('All tools failed');

        $result = new ToolCallingResult(response: $response, executions: [$fail1, $fail2], iterations: 1);

        self::assertFalse($result->allSucceeded());
        self::assertCount(2, $result->executions);
        self::assertTrue($result->response->isError());
    }
}
