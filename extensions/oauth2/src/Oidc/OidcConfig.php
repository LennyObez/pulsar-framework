<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use Pulsar\Api\Api;

/**
 * Configuration for the OpenID Connect provider.
 */
#[Api(since: '1.0.0')]
final readonly class OidcConfig
{
    /**
     * @param string $issuer The issuer identifier (e.g., https://auth.example.com)
     * @param list<string> $signingAlgorithms Supported ID token signing algorithms
     * @param bool $pairwiseSubjects Whether pairwise subject identifiers are supported
     * @param string $signingKeyId The Keyring kid for the current signing key
     */
    public function __construct(
        public string $issuer,
        public array $signingAlgorithms = ['RS256', 'ES256'],
        public bool $pairwiseSubjects = false,
        public string $signingKeyId = 'oauth_sign',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer: (string) ($data['issuer'] ?? ''),
            signingAlgorithms: (array) ($data['signing_algorithms'] ?? ['RS256', 'ES256']),
            pairwiseSubjects: (bool) ($data['pairwise_subjects'] ?? false),
            signingKeyId: (string) ($data['signing_key_id'] ?? 'oauth_sign'),
        );
    }
}
