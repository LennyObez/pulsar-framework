<?php

declare(strict_types=1);

namespace Pulsar\AI\Pipeline;

use Pulsar\AI\AiResponse;
use Pulsar\Api\Api;

/**
 * Result of a structured output extraction.
 */
#[Api(since: '1.0.0')]
final readonly class StructuredOutputResult
{
    /**
     * @param array<string, mixed> $data Parsed structured data
     * @param string $rawContent Raw response content from the model
     * @param bool $isValid Whether the response was valid JSON
     * @param AiResponse $response The underlying AI response
     */
    public function __construct(
        public array $data,
        public string $rawContent,
        public bool $isValid,
        public AiResponse $response,
    ) {}

    /**
     * Get a value from the extracted data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
