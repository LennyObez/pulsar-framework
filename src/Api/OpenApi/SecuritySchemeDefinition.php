<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Describes a security scheme for the OpenAPI specification.
 *
 * Supports OAuth2 (with flows), HTTP Bearer, and API key schemes.
 */
#[Api(since: '1.0.0')]
final readonly class SecuritySchemeDefinition
{
    /**
     * @param string $name Scheme identifier (referenced in endpoint security requirements)
     * @param SecuritySchemeType $type The security scheme type
     * @param string $description Human-readable description
     * @param string|null $scheme HTTP auth scheme (e.g., 'bearer') — for type=http
     * @param string|null $bearerFormat Token format hint (e.g., 'JWT') — for type=http+bearer
     * @param string|null $apiKeyName Header or query parameter name — for type=apiKey
     * @param string|null $apiKeyIn Location: 'header', 'query', or 'cookie' — for type=apiKey
     * @param array<string, array<string, mixed>>|null $flows OAuth2 flows definition — for type=oauth2
     */
    public function __construct(
        public string $name,
        public SecuritySchemeType $type,
        public string $description = '',
        public ?string $scheme = null,
        public ?string $bearerFormat = null,
        public ?string $apiKeyName = null,
        public ?string $apiKeyIn = null,
        public ?array $flows = null,
    ) {}

    /**
     * Create a Bearer token (JWT) security scheme.
     */
    #[NoDiscard]
    public static function bearer(string $name = 'bearerAuth', string $description = 'JWT Bearer token'): self
    {
        return new self(
            name: $name,
            type: SecuritySchemeType::Http,
            description: $description,
            scheme: 'bearer',
            bearerFormat: 'JWT',
        );
    }

    /**
     * Create an API key security scheme.
     */
    #[NoDiscard]
    public static function apiKey(
        string $name = 'apiKeyAuth',
        string $headerName = 'X-API-Key',
        string $in = 'header',
        string $description = 'API key authentication',
    ): self {
        return new self(
            name: $name,
            type: SecuritySchemeType::ApiKey,
            description: $description,
            apiKeyName: $headerName,
            apiKeyIn: $in,
        );
    }

    /**
     * Create an OAuth2 security scheme with authorization code flow.
     *
     * @param array<string, string> $scopes Available scopes as name => description
     */
    #[NoDiscard]
    public static function oauth2(
        string $authorizationUrl,
        string $tokenUrl,
        array $scopes = [],
        string $name = 'oauth2',
        string $description = 'OAuth 2.0 authorization code flow',
    ): self {
        return new self(
            name: $name,
            type: SecuritySchemeType::OAuth2,
            description: $description,
            flows: [
                'authorizationCode' => [
                    'authorizationUrl' => $authorizationUrl,
                    'tokenUrl' => $tokenUrl,
                    'scopes' => $scopes,
                ],
            ],
        );
    }

    /**
     * Convert to an OpenAPI security scheme object.
     *
     * @return array<string, mixed>
     */
    public function toOpenApi(): array
    {
        $scheme = ['type' => $this->type->value];

        if ($this->description !== '') {
            $scheme['description'] = $this->description;
        }

        return match ($this->type) {
            SecuritySchemeType::Http => $this->buildHttpScheme($scheme),
            SecuritySchemeType::ApiKey => $this->buildApiKeyScheme($scheme),
            SecuritySchemeType::OAuth2 => $this->buildOAuth2Scheme($scheme),
            SecuritySchemeType::OpenIdConnect => $scheme,
        };
    }

    /**
     * @param array<string, mixed> $scheme
     *
     * @return array<string, mixed>
     */
    private function buildHttpScheme(array $scheme): array
    {
        if ($this->scheme !== null) {
            $scheme['scheme'] = $this->scheme;
        }

        if ($this->bearerFormat !== null) {
            $scheme['bearerFormat'] = $this->bearerFormat;
        }

        return $scheme;
    }

    /**
     * @param array<string, mixed> $scheme
     *
     * @return array<string, mixed>
     */
    private function buildApiKeyScheme(array $scheme): array
    {
        if ($this->apiKeyName !== null) {
            $scheme['name'] = $this->apiKeyName;
        }

        if ($this->apiKeyIn !== null) {
            $scheme['in'] = $this->apiKeyIn;
        }

        return $scheme;
    }

    /**
     * @param array<string, mixed> $scheme
     *
     * @return array<string, mixed>
     */
    private function buildOAuth2Scheme(array $scheme): array
    {
        if ($this->flows !== null) {
            $scheme['flows'] = $this->flows;
        }

        return $scheme;
    }
}
