<?php

declare(strict_types=1);

namespace Pulsar\AI\Config;

use Pulsar\AI\ToolDefinition;
use Pulsar\Api\Api;

/**
 * Per-request options for AI completions.
 *
 * Overrides the global AiConfig defaults for a single request.
 */
#[Api(since: '1.0.0')]
final readonly class AiRequestOptions
{
    /**
     * @param float|null $temperature Sampling temperature (0.0-2.0); null = use default
     * @param int|null $maxTokens Maximum output tokens; null = use default
     * @param string|null $systemPrompt System/instruction prompt
     * @param string|null $model Override the default model for this request
     * @param int $timeoutSeconds Request timeout
     * @param list<ToolDefinition> $tools Available tools for function calling
     * @param string|null $responseFormat Force output format: 'json', 'text', or null
     */
    public function __construct(
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?string $systemPrompt = null,
        public ?string $model = null,
        public int $timeoutSeconds = 120,
        public array $tools = [],
        public ?string $responseFormat = null,
    ) {}
}
