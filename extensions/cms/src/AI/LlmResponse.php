<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

use function is_string;

/**
 * Immutable DTO representing a response from an LLM provider.
 *
 * @psalm-api Public DTO returned from LLM clients; consumed by user-land code
 *            and admin templates.
 */
#[Api(since: '1.0.0')]
final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public string $finishReason,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: is_string($data['content'] ?? null) ? $data['content'] : '',
            inputTokens: is_numeric($data['input_tokens'] ?? null) ? (int) $data['input_tokens'] : 0,
            outputTokens: is_numeric($data['output_tokens'] ?? null) ? (int) $data['output_tokens'] : 0,
            finishReason: is_string($data['finish_reason'] ?? null) ? $data['finish_reason'] : 'stop',
        );
    }
}
