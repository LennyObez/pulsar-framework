<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Exception\SsoException;

#[Api(since: '1.0.0')]
final readonly class ProviderConfig
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $name,
        public string $type,
        public string $clientId,
        #[\SensitiveParameter] public string $clientSecret,
        public string $authorizationUrl,
        public string $tokenUrl,
        public ?string $jwksUri,
        public ?string $issuer,
        public array $scopes,
        public ?string $redirectUri,
        public bool $allowUnverifiedIdToken = false,
        public int $maxClockSkewSeconds = 120,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws SsoException
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        $jwksUri = isset($data['jwks_uri']) ? (string) $data['jwks_uri'] : null;

        if ($jwksUri !== null && !str_starts_with($jwksUri, 'https://')) {
            throw SsoException::invalidIdToken('jwksUri must use HTTPS');
        }

        $scopes = (array) ($data['scopes'] ?? []);
        /** @var list<string> $scopes */

        return new self(
            name: $name,
            type: (string) ($data['type'] ?? 'oidc'),
            clientId: (string) ($data['client_id'] ?? ''),
            clientSecret: (string) ($data['client_secret'] ?? ''),
            authorizationUrl: (string) ($data['authorization_url'] ?? ''),
            tokenUrl: (string) ($data['token_url'] ?? ''),
            jwksUri: $jwksUri,
            issuer: isset($data['issuer']) ? (string) $data['issuer'] : null,
            scopes: $scopes,
            redirectUri: isset($data['redirect_uri']) ? (string) $data['redirect_uri'] : null,
            allowUnverifiedIdToken: (bool) ($data['allow_unverified_id_token'] ?? false),
            maxClockSkewSeconds: (int) ($data['max_clock_skew_seconds'] ?? 120),
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'clientId' => $this->clientId,
            'clientSecret' => '[REDACTED]',
            'authorizationUrl' => $this->authorizationUrl,
            'tokenUrl' => $this->tokenUrl,
        ];
    }
}
