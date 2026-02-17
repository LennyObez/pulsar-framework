<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Pipeline\ToolCallExecution;
use Pulsar\AI\ToolCall;

#[CoversClass(ToolCallExecution::class)]
final class ToolCallExecutionTest extends TestCase
{
    #[Test]
    public function successfulExecutionPropertiesAreAccessible(): void
    {
        $toolCall = new ToolCall(id: 'tc_1', name: 'get_weather', arguments: ['city' => 'London']);
        $execution = new ToolCallExecution(
            toolCall: $toolCall,
            output: '{"temp": 15, "unit": "celsius"}',
            succeeded: true,
        );

        self::assertSame($toolCall, $execution->toolCall);
        self::assertSame('{"temp": 15, "unit": "celsius"}', $execution->output);
        self::assertTrue($execution->succeeded);
    }

    #[Test]
    public function failedExecutionPropertiesAreAccessible(): void
    {
        $toolCall = new ToolCall(id: 'tc_2', name: 'db_query', arguments: ['sql' => 'SELECT 1']);
        $execution = new ToolCallExecution(
            toolCall: $toolCall,
            output: 'Error: Connection refused',
            succeeded: false,
        );

        self::assertSame('db_query', $execution->toolCall->name);
        self::assertSame('Error: Connection refused', $execution->output);
        self::assertFalse($execution->succeeded);
    }

    #[Test]
    public function emptyOutputIsAllowed(): void
    {
        $toolCall = new ToolCall(id: 'tc_3', name: 'delete_item', arguments: ['id' => '42']);
        $execution = new ToolCallExecution(
            toolCall: $toolCall,
            output: '',
            succeeded: true,
        );

        self::assertSame('', $execution->output);
        self::assertTrue($execution->succeeded);
    }

    #[Test]
    public function toolCallArgumentsArePreserved(): void
    {
        $args = ['query' => 'pulsar framework', 'limit' => 5, 'filters' => ['lang' => 'en']];
        $toolCall = new ToolCall(id: 'tc_4', name: 'search', arguments: $args);
        $execution = new ToolCallExecution(toolCall: $toolCall, output: 'results', succeeded: true);

        self::assertSame($args, $execution->toolCall->arguments);
    }
}
