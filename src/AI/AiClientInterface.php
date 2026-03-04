<?php

declare(strict_types=1);

namespace Pulsar\AI;

use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\Api\Api;

/**
 * Provider-agnostic AI client interface.
 *
 * Supports chat completions, single-prompt completions, embeddings,
 * and structured output with JSON schema enforcement.
 */
#[Api(since: '1.0.0')]
interface AiClientInterface
{
    /**
     * Send a multi-turn chat conversation and return the model's response.
     *
     * @param list<ChatMessage> $messages Conversation messages
     */
    public function chat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiResponse;

    /**
     * Send a single prompt and return the completion.
     */
    public function complete(string $prompt, AiRequestOptions $options = new AiRequestOptions()): AiResponse;

    /**
     * Generate embeddings for the given input texts.
     *
     * @param list<string> $inputs Texts to embed
     * @return EmbeddingResult
     */
    public function embed(array $inputs, AiRequestOptions $options = new AiRequestOptions()): EmbeddingResult;

    /**
     * Generate a response constrained to a JSON schema.
     *
     * The model output is guaranteed to conform to the provided schema,
     * enabling reliable structured data extraction.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     */
    public function structuredOutput(
        string $prompt,
        array $schema,
        AiRequestOptions $options = new AiRequestOptions(),
    ): AiResponse;

    /**
     * Provider identifier for logging and diagnostics.
     */
    public function providerName(): string;
}
