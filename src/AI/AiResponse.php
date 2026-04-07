<?php

declare(strict_types=1);

namespace Pulsar\AI;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     content?: string,
     *     input_tokens?: int|string,
     *     output_tokens?: int|string,
     *     finish_reason?: string,
     *     tool_calls?: list<array<string, mixed>>,
     *     model?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $toolCalls = [];

        foreach ($data['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = ToolCall::fromArray($tc);
        }

        return new self(
            content: $data['content'] ?? '',
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            finishReason: $data['finish_reason'] ?? 'stop',
            toolCalls: $toolCalls,
            model: $data['model'] ?? '',
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
