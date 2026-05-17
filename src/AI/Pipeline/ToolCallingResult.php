<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use Pulsar\AI\AiResponse;
use Pulsar\Api\Api;

/**
 * Result of a tool-calling pipeline execution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ToolCallingResult
{
    /**
     * @param AiResponse $response The final AI response
     * @param list<ToolCallExecution> $executions All tool call executions performed
     * @param int $iterations Number of LLM round-trips
     */
    public function __construct(
        public AiResponse $response,
        public array $executions,
        public int $iterations,
    ) {}

    /**
     * Whether all tool calls succeeded.
     */
    public function allSucceeded(): bool
    {
        foreach ($this->executions as $exec) {
            if (!$exec->succeeded) {
                return false;
            }
        }

        return true;
    }
}
