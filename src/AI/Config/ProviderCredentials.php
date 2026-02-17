<?php

declare(strict_types=1);

namespace Pulsar\AI\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * API credentials and endpoint configuration for a single AI provider.
 */
#[Api(since: '1.0.0')]
final readonly class ProviderCredentials
{
    /**
     * @param string $apiKey API key or token for authentication
     * @param string $baseUrl Custom base URL; empty = provider default
     * @param string $organization Organization ID (OpenAI-specific)
     */
    public function __construct(
        public string $apiKey = '',
        public string $baseUrl = '',
        public string $organization = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: is_string($data['api_key'] ?? null) ? $data['api_key'] : '',
            baseUrl: is_string($data['base_url'] ?? null) ? $data['base_url'] : '',
            organization: is_string($data['organization'] ?? null) ? $data['organization'] : '',
        );
    }
}
