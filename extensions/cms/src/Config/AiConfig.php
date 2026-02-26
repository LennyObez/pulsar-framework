<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * AI content assistant configuration.
 *
 * Controls which LLM provider to use and connection parameters.
 * Disabled by default — requires explicit opt-in and API key configuration.
 */
#[Api(since: '1.0.0')]
final readonly class AiConfig
{
    /**
     * @param bool $enabled Whether the AI assistant is enabled
     * @param string $provider Provider identifier: 'openai' or 'anthropic'
     * @param string $model Model name (e.g., 'gpt-4o', 'claude-sonnet-4-6'); empty = provider default
     * @param string $apiKey API key for the chosen provider
     * @param string $baseUrl Custom base URL for the provider API; empty = provider default
     */
    public function __construct(
        public bool $enabled = false,
        public string $provider = 'openai',
        public string $model = '',
        public string $apiKey = '',
        public string $baseUrl = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            provider: (string) ($data['provider'] ?? 'openai'),
            model: (string) ($data['model'] ?? ''),
            apiKey: (string) ($data['api_key'] ?? ''),
            baseUrl: (string) ($data['base_url'] ?? ''),
        );
    }
}
