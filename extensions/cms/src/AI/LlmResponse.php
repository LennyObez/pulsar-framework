<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: Coerce::string($data['content'] ?? null),
            inputTokens: Coerce::int($data['input_tokens'] ?? null, 0),
            outputTokens: Coerce::int($data['output_tokens'] ?? null, 0),
            finishReason: Coerce::string($data['finish_reason'] ?? null, 'stop'),
        );
    }
}
