<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Oidc;

use Pulsar\Api\Internal;

/**
 * OpenID Connect Discovery endpoint handler.
 *
 * Serves the OpenID Provider Configuration at /.well-known/openid-configuration
 * per OpenID Connect Discovery 1.0.
 */
#[Internal(reason: 'Implementation detail — use AuthorizationServerInterface')]
final readonly class OidcDiscovery
{
    public function __construct(
        private OidcConfig $config,
    ) {}

    /**
     * Generate the OpenID Provider configuration document.
     *
     * @return array<string, mixed>
     */
    public function configurationDocument(): array
    {
        $issuer = $this->config->issuer;

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/oauth/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'introspection_endpoint' => $issuer . '/oauth/introspect',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'scopes_supported' => ['openid', 'profile', 'email', 'address', 'phone'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token'],
            'subject_types_supported' => $this->config->pairwiseSubjects
                ? ['public', 'pairwise']
                : ['public'],
            'id_token_signing_alg_values_supported' => $this->config->signingAlgorithms,
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'claims_supported' => [
                'sub', 'iss', 'aud', 'exp', 'iat', 'nonce',
                'name', 'family_name', 'given_name', 'middle_name',
                'nickname', 'preferred_username', 'profile', 'picture',
                'website', 'gender', 'birthdate', 'zoneinfo', 'locale',
                'updated_at', 'email', 'email_verified',
                'address', 'phone_number', 'phone_number_verified',
            ],
            'code_challenge_methods_supported' => ['S256'],
            'service_documentation' => $issuer . '/docs/oauth2-oidc',
        ];
    }
}
