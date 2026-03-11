<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

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
     * @param string $authorizationCodeStore Storage backend for authorization codes:
     *                                       'memory' (default, dev/testing only) or
     *                                       'database' (F385.12, production-grade
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $issuer */
        $issuer = isset($data['issuer']) && is_string($data['issuer']) ? $data['issuer'] : '';
        /** @var int $atTtl */
        $atTtl = isset($data['access_token_ttl']) && is_int($data['access_token_ttl']) ? $data['access_token_ttl'] : 900;
        /** @var int $rtTtl */
        $rtTtl = isset($data['refresh_token_ttl']) && is_int($data['refresh_token_ttl']) ? $data['refresh_token_ttl'] : 2_592_000;
        /** @var int $acTtl */
        $acTtl = isset($data['authorization_code_ttl']) && is_int($data['authorization_code_ttl']) ? $data['authorization_code_ttl'] : 600;
        $sigAlgsRaw = $data['signing_algorithms'] ?? null;
        /** @var list<string> $sigAlgsList */
        $sigAlgsList = is_array($sigAlgsRaw) ? array_values($sigAlgsRaw) : ['RS256', 'ES256'];
        /** @var string $sigKeyId */
        $sigKeyId = isset($data['signing_key_id']) && is_string($data['signing_key_id']) ? $data['signing_key_id'] : 'oauth_sign';
        /** @var string $tokenFmt */
        $tokenFmt = isset($data['token_format']) && is_string($data['token_format']) ? $data['token_format'] : 'reference';
        /** @var string $authCodeStore */
        $authCodeStore = isset($data['authorization_code_store']) && is_string($data['authorization_code_store'])
            ? $data['authorization_code_store']
            : 'memory';

        return new self(
            issuer: $issuer,
            accessTokenTtl: $atTtl,
            refreshTokenTtl: $rtTtl,
            authorizationCodeTtl: $acTtl,
            dynamicRegistration: (bool) ($data['dynamic_registration'] ?? false),
            signingAlgorithms: $sigAlgsList,
            pairwiseSubjects: (bool) ($data['pairwise_subjects'] ?? false),
            signingKeyId: $sigKeyId,
            tokenFormat: $tokenFmt,
            authorizationCodeStore: $authCodeStore,
        );
    }
}
