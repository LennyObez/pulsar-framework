<?php

declare(strict_types=1);

namespace Pulsar\AI\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * API credentials and endpoint configuration for a single AI provider.
 * @api
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
            apiKey: Coerce::string($data['api_key'] ?? null),
            baseUrl: Coerce::string($data['base_url'] ?? null),
            organization: Coerce::string($data['organization'] ?? null),
        );
    }
}
