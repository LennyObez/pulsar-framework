<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Client;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function in_array;

/**
 * OAuth2 client entity.
 */
#[Api(since: '1.0.0')]
final readonly class OAuthClient
{
    /**
     * @param list<string> $redirectUris Registered redirect URIs (exact match required)
     * @param list<string> $grantTypes Allowed grant types
     * @param list<string> $scopes Allowed scopes
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $redirectUris,
        public array $grantTypes,
        public array $scopes,
        public bool $confidential,
        public bool $active = true,
        public ?string $secretHash = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    /**
     * Check if a redirect URI is registered for this client.
     *
     * Strict exact match: no wildcards.
     */
    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }

    /**
     * Check if a grant type is allowed for this client.
     */
    public function hasGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'redirectUris' => $this->redirectUris,
            'grantTypes' => $this->grantTypes,
            'scopes' => $this->scopes,
            'confidential' => $this->confidential,
            'active' => $this->active,
            'secretHash' => $this->secretHash !== null ? '[REDACTED]' : null,
            'createdAt' => $this->createdAt?->format('c'),
        ];
    }
}
