<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use Pulsar\AI\ToolCall;
use Pulsar\Api\Api;

/**
 * Record of a single tool call execution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ToolCallExecution
{
    /**
     * @param ToolCall $toolCall The tool call that was executed
     * @param string $output The output/result from the tool handler
     * @param bool $succeeded Whether the execution completed without error
     */
    public function __construct(
        public ToolCall $toolCall,
        public string $output,
        public bool $succeeded,
    ) {}
}
