<?php

declare(strict_types=1);

namespace Pulsar\AI\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     api_key?: string,
     *     base_url?: string,
     *     organization?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            apiKey: $data['api_key'] ?? '',
            baseUrl: $data['base_url'] ?? '',
            organization: $data['organization'] ?? '',
        );
    }
}
