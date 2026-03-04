<?php

declare(strict_types=1);

namespace Pulsar\AI;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Pre-registered model metadata for well-known AI models.
 *
 * Provides default context windows, pricing tiers, and capability flags
 * so applications can make informed routing decisions.
 */
#[Api(since: '1.0.0')]
final class ModelCard
{
    /**
     * @param string $id Model identifier (e.g., 'claude-sonnet-4-6', 'gpt-4o')
     * @param string $provider Provider name ('anthropic', 'openai', 'ollama')
     * @param int $contextWindow Maximum context window in tokens
     * @param int $maxOutputTokens Maximum output tokens per request
     * @param bool $supportsVision Whether the model accepts image inputs
     * @param bool $supportsToolCalling Whether the model supports function/tool calling
     * @param bool $supportsStructuredOutput Whether the model supports JSON schema output
     * @param bool $supportsEmbeddings Whether the model can generate embeddings
     * @param int $embeddingDimensions Embedding vector dimensions (0 if not an embedding model)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly int $contextWindow,
        public readonly int $maxOutputTokens,
        public readonly bool $supportsVision = false,
        public readonly bool $supportsToolCalling = false,
        public readonly bool $supportsStructuredOutput = false,
        public readonly bool $supportsEmbeddings = false,
        public readonly int $embeddingDimensions = 0,
    ) {}

    /** @var array<string, self>|null */
    private static ?array $registry = null;

    /**
     * Get a pre-registered model card by model ID.
     *
     * Returns null if the model is not in the registry.
     */
    #[NoDiscard]
    public static function lookup(string $modelId): ?self
    {
        return self::registry()[$modelId] ?? null;
    }

    /**
     * Get all registered model cards.
     *
     * @return array<string, self>
     */
    #[NoDiscard]
    public static function all(): array
    {
        return self::registry();
    }

    /**
     * @return array<string, self>
     */
    private static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        self::$registry = [
            // Anthropic Claude models
            'claude-sonnet-4-6' => new self(
                id: 'claude-sonnet-4-6',
                provider: 'anthropic',
                contextWindow: 200_000,
                maxOutputTokens: 16_384,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),
            'claude-opus-4-6' => new self(
                id: 'claude-opus-4-6',
                provider: 'anthropic',
                contextWindow: 200_000,
                maxOutputTokens: 32_000,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),
            'claude-haiku-3-5' => new self(
                id: 'claude-haiku-3-5',
                provider: 'anthropic',
                contextWindow: 200_000,
                maxOutputTokens: 8_192,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),

            // OpenAI GPT models
            'gpt-4o' => new self(
                id: 'gpt-4o',
                provider: 'openai',
                contextWindow: 128_000,
                maxOutputTokens: 16_384,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),
            'gpt-4o-mini' => new self(
                id: 'gpt-4o-mini',
                provider: 'openai',
                contextWindow: 128_000,
                maxOutputTokens: 16_384,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),
            'o3' => new self(
                id: 'o3',
                provider: 'openai',
                contextWindow: 200_000,
                maxOutputTokens: 100_000,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),
            'o4-mini' => new self(
                id: 'o4-mini',
                provider: 'openai',
                contextWindow: 200_000,
                maxOutputTokens: 100_000,
                supportsVision: true,
                supportsToolCalling: true,
                supportsStructuredOutput: true,
            ),

            // OpenAI Embedding models
            'text-embedding-3-small' => new self(
                id: 'text-embedding-3-small',
                provider: 'openai',
                contextWindow: 8_191,
                maxOutputTokens: 0,
                supportsEmbeddings: true,
                embeddingDimensions: 1536,
            ),
            'text-embedding-3-large' => new self(
                id: 'text-embedding-3-large',
                provider: 'openai',
                contextWindow: 8_191,
                maxOutputTokens: 0,
                supportsEmbeddings: true,
                embeddingDimensions: 3072,
            ),
        ];

        return self::$registry;
    }
}
