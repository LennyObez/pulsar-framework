<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

/**
 * Immutable DTO representing a response from an LLM provider.
 *
 * @psalm-api Public DTO returned from LLM clients; consumed by user-land code
 *            and admin templates.
 * @api
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
     * @param array{
     *     content?: string,
     *     input_tokens?: int,
     *     output_tokens?: int,
     *     finish_reason?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: $data['content'] ?? '',
            inputTokens: $data['input_tokens'] ?? 0,
            outputTokens: $data['output_tokens'] ?? 0,
            finishReason: $data['finish_reason'] ?? 'stop',
        );
    }
}
