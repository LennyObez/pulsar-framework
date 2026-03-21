<?php

declare(strict_types=1);

namespace Pulsar\AI;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_numeric;
use function is_string;

/**
 * Immutable DTO representing a response from an AI provider.
 *
 * Carries the generated content, token usage metrics, finish reason,
 * and optional tool call data for function-calling workflows.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiResponse
{
    /**
     * @param string $content Generated text content
     * @param int $inputTokens Number of input/prompt tokens consumed
     * @param int $outputTokens Number of output/completion tokens generated
     * @param string $finishReason Why generation stopped: 'stop', 'length', 'tool_use', 'error'
     * @param list<ToolCall> $toolCalls Tool/function calls requested by the model
     * @param string $model The model that generated this response
     */
    public function __construct(
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public string $finishReason,
        public array $toolCalls = [],
        public string $model = '',
    ) {}

    /**
     * Total tokens consumed (input + output).
     */
    #[NoDiscard]
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Whether the response completed normally (not truncated or errored).
     */
    public function isComplete(): bool
    {
        return $this->finishReason === 'stop';
    }

    /**
     * Whether the model requested tool/function calls.
     */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * Whether the response indicates an error.
     */
    public function isError(): bool
    {
        return $this->finishReason === 'error';
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<ToolCall> $toolCalls */
        $toolCalls = [];

        if (isset($data['tool_calls']) && is_array($data['tool_calls'])) {
            foreach ($data['tool_calls'] as $tc) {
                if (is_array($tc)) {
                    /** @var array<string, mixed> $tc */
                    $toolCalls[] = ToolCall::fromArray($tc);
                }
            }
        }

        return new self(
            content: is_string($data['content'] ?? null) ? $data['content'] : '',
            inputTokens: is_numeric($data['input_tokens'] ?? null) ? (int) $data['input_tokens'] : 0,
            outputTokens: is_numeric($data['output_tokens'] ?? null) ? (int) $data['output_tokens'] : 0,
            finishReason: is_string($data['finish_reason'] ?? null) ? $data['finish_reason'] : 'stop',
            toolCalls: $toolCalls,
            model: is_string($data['model'] ?? null) ? $data['model'] : '',
        );
    }

    /**
     * Create an error response.
     */
    #[NoDiscard]
    public static function error(string $message): self
    {
        return new self(
            content: $message,
            inputTokens: 0,
            outputTokens: 0,
            finishReason: 'error',
        );
    }
}
