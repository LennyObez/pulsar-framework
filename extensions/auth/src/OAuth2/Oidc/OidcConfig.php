<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Api;

use function array_values;

/**
 * Configuration for the OpenID Connect provider.
 * @api
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
     * @param array{
     *     issuer?: string,
     *     signing_algorithms?: list<string>,
     *     pairwise_subjects?: bool|int|string,
     *     signing_key_id?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer: $data['issuer'] ?? '',
            signingAlgorithms: array_values($data['signing_algorithms'] ?? ['RS256', 'ES256']),
            pairwiseSubjects: (bool) ($data['pairwise_subjects'] ?? false),
            signingKeyId: $data['signing_key_id'] ?? 'oauth_sign',
        );
    }
}
