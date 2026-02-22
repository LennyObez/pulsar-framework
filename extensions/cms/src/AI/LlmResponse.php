<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

/**
 * Immutable DTO representing a response from an LLM provider.
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
            content: (string) ($data['content'] ?? ''),
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            finishReason: (string) ($data['finish_reason'] ?? 'stop'),
        );
    }
}
