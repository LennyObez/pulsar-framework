<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use SensitiveParameter;

use function is_int;
use function is_string;

#[Api(since: '1.0.0')]
final readonly class ProviderConfig
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $name,
        public string $type,
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
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
        /** @var string|null $jwksUri */
        $jwksUri = isset($data['jwks_uri']) && is_string($data['jwks_uri']) ? $data['jwks_uri'] : null;

        if ($jwksUri !== null && !str_starts_with($jwksUri, 'https://')) {
            throw SsoException::invalidIdToken('jwksUri must use HTTPS');
        }

        $scopes = (array) ($data['scopes'] ?? []);
        /** @var list<string> $scopes */

        /** @var string $type */
        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : 'oidc';
        /** @var string $clientId */
        $clientId = isset($data['client_id']) && is_string($data['client_id']) ? $data['client_id'] : '';
        /** @var string $clientSecret */
        $clientSecret = isset($data['client_secret']) && is_string($data['client_secret']) ? $data['client_secret'] : '';
        /** @var string $authUrl */
        $authUrl = isset($data['authorization_url']) && is_string($data['authorization_url']) ? $data['authorization_url'] : '';
        /** @var string $tokenUrl */
        $tokenUrl = isset($data['token_url']) && is_string($data['token_url']) ? $data['token_url'] : '';
        /** @var string|null $issuer */
        $issuer = isset($data['issuer']) && is_string($data['issuer']) ? $data['issuer'] : null;
        /** @var string|null $redirectUri */
        $redirectUri = isset($data['redirect_uri']) && is_string($data['redirect_uri']) ? $data['redirect_uri'] : null;
        /** @var int $clockSkew */
        $clockSkew = isset($data['max_clock_skew_seconds']) && is_int($data['max_clock_skew_seconds']) ? $data['max_clock_skew_seconds'] : 120;

        return new self(
            name: $name,
            type: $type,
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizationUrl: $authUrl,
            tokenUrl: $tokenUrl,
            jwksUri: $jwksUri,
            issuer: $issuer,
            scopes: $scopes,
            redirectUri: $redirectUri,
            allowUnverifiedIdToken: (bool) ($data['allow_unverified_id_token'] ?? false),
            maxClockSkewSeconds: $clockSkew,
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
