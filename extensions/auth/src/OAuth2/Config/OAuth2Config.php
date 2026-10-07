<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Config;

use Pulsar\Api\Api;

use function array_values;

/**
 * Configuration for the OAuth2 authorization server.
 * @api
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
     * @param string $authorizationCodeStore Storage backend for authorization codes:
     *                                       'memory' (default, dev/testing only) or
     *                                       'database' (production-grade
     *                                       persistence via {@see Pulsar\Database\ConnectionInterface}).
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
        public string $authorizationCodeStore = 'memory',
    ) {}

    /**
     * @param array{
     *     issuer?: string,
     *     access_token_ttl?: int,
     *     refresh_token_ttl?: int,
     *     authorization_code_ttl?: int,
     *     dynamic_registration?: bool|int|string,
     *     signing_algorithms?: list<string>,
     *     pairwise_subjects?: bool|int|string,
     *     signing_key_id?: string,
     *     token_format?: string,
     *     authorization_code_store?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer: $data['issuer'] ?? '',
            accessTokenTtl: $data['access_token_ttl'] ?? 900,
            refreshTokenTtl: $data['refresh_token_ttl'] ?? 2_592_000,
            authorizationCodeTtl: $data['authorization_code_ttl'] ?? 600,
            dynamicRegistration: (bool) ($data['dynamic_registration'] ?? false),
            signingAlgorithms: array_values($data['signing_algorithms'] ?? ['RS256', 'ES256']),
            pairwiseSubjects: (bool) ($data['pairwise_subjects'] ?? false),
            signingKeyId: $data['signing_key_id'] ?? 'oauth_sign',
            tokenFormat: $data['token_format'] ?? 'reference',
            authorizationCodeStore: $data['authorization_code_store'] ?? 'memory',
        );
    }
}
