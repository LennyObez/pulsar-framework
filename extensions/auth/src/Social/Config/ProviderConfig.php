<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use SensitiveParameter;

use function array_values;
use function str_starts_with;

#[Api(since: '1.0.0')]
final readonly class ProviderConfig
{
    /**
     * @param list<string> $scopes
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
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
     * @param array{
     *     type?: string,
     *     client_id?: string,
     *     client_secret?: string,
     *     authorization_url?: string,
     *     token_url?: string,
     *     jwks_uri?: string|null,
     *     issuer?: string|null,
     *     scopes?: list<string>,
     *     redirect_uri?: string|null,
     *     allow_unverified_id_token?: bool|int|string,
     *     max_clock_skew_seconds?: int,
     * } $data
     * @throws SsoException
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        $jwksUri = $data['jwks_uri'] ?? null;

        if ($jwksUri !== null && !str_starts_with($jwksUri, 'https://')) {
            throw SsoException::invalidIdToken('jwksUri must use HTTPS');
        }

        return new self(
            name: $name,
            type: $data['type'] ?? 'oidc',
            clientId: $data['client_id'] ?? '',
            clientSecret: $data['client_secret'] ?? '',
            authorizationUrl: $data['authorization_url'] ?? '',
            tokenUrl: $data['token_url'] ?? '',
            jwksUri: $jwksUri,
            issuer: $data['issuer'] ?? null,
            scopes: array_values($data['scopes'] ?? []),
            redirectUri: $data['redirect_uri'] ?? null,
            allowUnverifiedIdToken: (bool) ($data['allow_unverified_id_token'] ?? false),
            maxClockSkewSeconds: $data['max_clock_skew_seconds'] ?? 120,
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
