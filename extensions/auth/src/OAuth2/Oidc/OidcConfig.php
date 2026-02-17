<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Oidc;

use Pulsar\Api\Api;

use function is_array;
use function is_string;

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
        /** @var string $issuer */
        $issuer = isset($data['issuer']) && is_string($data['issuer']) ? $data['issuer'] : '';
        $sigAlgsRaw = $data['signing_algorithms'] ?? null;
        /** @var list<string> $sigAlgsList */
        $sigAlgsList = is_array($sigAlgsRaw) ? array_values($sigAlgsRaw) : ['RS256', 'ES256'];
        /** @var string $sigKeyId */
        $sigKeyId = isset($data['signing_key_id']) && is_string($data['signing_key_id']) ? $data['signing_key_id'] : 'oauth_sign';

        return new self(
            issuer: $issuer,
            signingAlgorithms: $sigAlgsList,
            pairwiseSubjects: (bool) ($data['pairwise_subjects'] ?? false),
            signingKeyId: $sigKeyId,
        );
    }
}
