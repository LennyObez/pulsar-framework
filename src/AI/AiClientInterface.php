<?php

declare(strict_types=1);

namespace Pulsar\AI;

use Pulsar\AI\Config\AiRequestOptions;
use Pulsar\AI\Embedding\EmbeddingResult;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\AiStream;
use Pulsar\Api\Api;

/**
 * Provider-agnostic AI client interface.
 *
 * Supports chat completions, single-prompt completions, embeddings,
 * structured output with JSON schema enforcement, and streamed chat.
 * @api
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
     * Send a multi-turn chat conversation and read the model's response as it
     * is generated.
     *
     * The returned {@see AiStream} yields {@see \Pulsar\AI\Streaming\AiStreamDelta}
     * objects — text, tool call fragments, token usage, and exactly one terminal
     * event — and then exposes the accumulated {@see AiResponse} through
     * {@see AiStream::response()}. That response carries the same token counts a
     * {@see chat()} call would have returned, so budgets and audit records do not
     * lose sight of a streamed call.
     *
     * A stream that ends without its terminal event throws
     * {@see AiStreamException} rather than returning a short response, so a
     * dropped connection can never be read as a finished answer.
     *
     * @param list<ChatMessage> $messages Conversation messages
     *
     * @throws AiStreamException When the stream fails before its terminal event
     */
    public function streamChat(array $messages, AiRequestOptions $options = new AiRequestOptions()): AiStream;

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
