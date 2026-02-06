<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Config;

use Pulsar\Api\Api;

/**
 * Configuration for the OAuth2 authorization server.
 */
#[Api(since: '1.0.0')]
final readonly class OAuth2Config
{
    /**
     * @param string $issuer The issuer identifier (e.g., https://auth.example.com)
     * @param int $accessTokenTtl Access token lifetime in seconds
     * @param int $refreshTokenTtl Refresh token lifetime in seconds
     * @param int $authorizationCodeTtl Authorization code lifetime in seconds
     * @param bool $dynamicRegistration Whether dynamic client registration is enabled
     * @param list<string> $signingAlgorithms Supported signing algorithms
     * @param bool $pairwiseSubjects Whether pairwise subject identifiers are supported
     * @param string $signingKeyId Keyring key identifier for token signing
     * @param string $tokenFormat Token format: 'reference' or 'jwt'
     */
    public function __construct(
        public string $issuer = '',
        public int $accessTokenTtl = 900,
        public int $refreshTokenTtl = 2_592_000,
        public int $authorizationCodeTtl = 600,
        public bool $dynamicRegistration = false,
        public array $signingAlgorithms = ['RS256', 'ES256'],
        public bool $pairwiseSubjects = false,
        public string $signingKeyId = 'oauth_sign',
        public string $tokenFormat = 'reference',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer: (string) ($data['issuer'] ?? ''),
            accessTokenTtl: (int) ($data['access_token_ttl'] ?? 900),
            refreshTokenTtl: (int) ($data['refresh_token_ttl'] ?? 2_592_000),
            authorizationCodeTtl: (int) ($data['authorization_code_ttl'] ?? 600),
            dynamicRegistration: (bool) ($data['dynamic_registration'] ?? false),
            signingAlgorithms: (array) ($data['signing_algorithms'] ?? ['RS256', 'ES256']),
            pairwiseSubjects: (bool) ($data['pairwise_subjects'] ?? false),
            signingKeyId: (string) ($data['signing_key_id'] ?? 'oauth_sign'),
            tokenFormat: (string) ($data['token_format'] ?? 'reference'),
        );
    }
}
