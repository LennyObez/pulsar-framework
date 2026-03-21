<?php

declare(strict_types=1);

namespace Pulsar\AI\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Top-level AI configuration.
 *
 * Controls the default provider, model selection, and API connection parameters.
 * Disabled by default; requires explicit opt-in and API key configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiConfig
{
    /**
     * @param bool $enabled Whether AI features are enabled
     * @param string $defaultProvider Default provider identifier ('anthropic', 'openai', 'ollama')
     * @param string $defaultModel Default model name; empty = provider default
     * @param float $defaultTemperature Default temperature for generation
     * @param int $defaultMaxTokens Default max output tokens
     * @param array<string, ProviderCredentials> $providers Provider-specific credentials
     */
    public function __construct(
        public bool $enabled = false,
        public string $defaultProvider = 'anthropic',
        public string $defaultModel = '',
        public float $defaultTemperature = 0.7,
        public int $defaultMaxTokens = 1024,
        public array $providers = [],
    ) {}

    /**
     * Get credentials for a specific provider.
     */
    public function credentialsFor(string $provider): ?ProviderCredentials
    {
        return $this->providers[$provider] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, ProviderCredentials> $providers */
        $providers = [];

        if (isset($data['providers']) && is_array($data['providers'])) {
            foreach ($data['providers'] as $name => $providerData) {
                if (is_string($name) && is_array($providerData)) {
                    /** @var array<string, mixed> $providerData */
                    $providers[$name] = ProviderCredentials::fromArray($providerData);
                }
            }
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            defaultProvider: is_string($data['default_provider'] ?? null) ? $data['default_provider'] : 'anthropic',
            defaultModel: is_string($data['default_model'] ?? null) ? $data['default_model'] : '',
            defaultTemperature: is_numeric($data['default_temperature'] ?? null) ? (float) $data['default_temperature'] : 0.7,
            defaultMaxTokens: is_numeric($data['default_max_tokens'] ?? null) ? (int) $data['default_max_tokens'] : 1024,
            providers: $providers,
        );
    }
}
