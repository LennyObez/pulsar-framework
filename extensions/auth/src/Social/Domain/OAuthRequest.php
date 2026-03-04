<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Domain;

use Pulsar\Api\Api;

/**
 * Immutable OAuth2 authorization request parameters.
 *
 * Captures all values needed to build the authorization URL, including
 * PKCE challenge data and OIDC nonce when applicable.
 */
#[Api(since: '1.0.0')]
final readonly class OAuthRequest
{
    /**
     * @param list<string> $scopes   Requested OAuth scopes
     * @param string $redirectUri    Callback URI registered with the provider
     * @param string $state          CSRF state parameter
     * @param ?string $nonce         OIDC nonce for ID token replay protection
     * @param ?string $codeChallenge PKCE code challenge (base64url-encoded)
     * @param string $codeChallengeMethod PKCE challenge method (S256 only)
     */
    public function __construct(
        public string $redirectUri,
        public array $scopes,
        public string $state,
        public ?string $nonce = null,
        public ?string $codeChallenge = null,
        public string $codeChallengeMethod = 'S256',
    ) {}
}
