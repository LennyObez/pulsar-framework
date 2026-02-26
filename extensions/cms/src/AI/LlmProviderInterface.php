<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

/**
 * Provider-agnostic interface for LLM completions.
 *
 * Implementations connect to specific LLM APIs (OpenAI, Anthropic, etc.).
 */
#[Api(since: '1.0.0')]
interface LlmProviderInterface
{
    /**
     * Send a prompt to the LLM and return the response.
     */
    public function complete(string $prompt, LlmOptions $options = new LlmOptions()): LlmResponse;

    /**
     * Provider name for logging and diagnostics.
     */
    public function name(): string;
}
