<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Config;

use NoDiscard;
use Pulsar\Api\Api;
use SensitiveParameter;

/**
 * Pre-built OAuth 2.0 / OIDC provider configurations.
 *
 * Each provider returns a partially-filled ProviderConfig with the
 * correct authorization URL, token URL, JWKS URI, issuer, and default
 * scopes. The caller supplies only their client_id, client_secret,
 * and optional redirect_uri.
 */
#[Api(since: '1.0.0')]
final class SocialProviders
{
    /**
     * Google OAuth 2.0 / OpenID Connect provider.
     */
    #[NoDiscard]
    public static function google(
        string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        ?string $redirectUri = null,
    ): ProviderConfig {
        return new ProviderConfig(
            name: 'google',
            type: 'oidc',
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizationUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenUrl: 'https://oauth2.googleapis.com/token',
            jwksUri: 'https://www.googleapis.com/oauth2/v3/certs',
            issuer: 'https://accounts.google.com',
            scopes: ['openid', 'email', 'profile'],
            redirectUri: $redirectUri,
        );
    }

    /**
     * GitHub OAuth 2.0 provider.
     *
     * Note: GitHub does not support OIDC natively; tokens are opaque.
     */
    #[NoDiscard]
    public static function github(
        string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        ?string $redirectUri = null,
    ): ProviderConfig {
        return new ProviderConfig(
            name: 'github',
            type: 'oauth2',
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizationUrl: 'https://github.com/login/oauth/authorize',
            tokenUrl: 'https://github.com/login/oauth/access_token',
            jwksUri: null,
            issuer: null,
            scopes: ['read:user', 'user:email'],
            redirectUri: $redirectUri,
        );
    }

    /**
     * Facebook / Meta OAuth 2.0 provider.
     */
    #[NoDiscard]
    public static function facebook(
        string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        ?string $redirectUri = null,
    ): ProviderConfig {
        return new ProviderConfig(
            name: 'facebook',
            type: 'oauth2',
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizationUrl: 'https://www.facebook.com/v19.0/dialog/oauth',
            tokenUrl: 'https://graph.facebook.com/v19.0/oauth/access_token',
            jwksUri: null,
            issuer: null,
            scopes: ['email', 'public_profile'],
            redirectUri: $redirectUri,
        );
    }

    /**
     * Apple Sign In (OIDC) provider.
     *
     * Apple uses response_mode=form_post and requires a signed JWT
     * as the client_secret. The `clientSecret` here should be the
     * signed JWT or a callback that generates it.
     */
    #[NoDiscard]
    public static function apple(
        string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        ?string $redirectUri = null,
    ): ProviderConfig {
        return new ProviderConfig(
            name: 'apple',
            type: 'oidc',
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizationUrl: 'https://appleid.apple.com/auth/authorize',
            tokenUrl: 'https://appleid.apple.com/auth/token',
            jwksUri: 'https://appleid.apple.com/auth/keys',
            issuer: 'https://appleid.apple.com',
            scopes: ['openid', 'email', 'name'],
            redirectUri: $redirectUri,
        );
    }

    /**
     * Microsoft Entra ID (Azure AD) OIDC provider.
     *
     * Uses the "common" tenant by default for multi-tenant apps.
     * For single-tenant, replace 'common' with your tenant ID.
     *
     * @param string $tenant Azure AD tenant ID or 'common' for multi-tenant
     */
    #[NoDiscard]
    public static function microsoft(
        string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        ?string $redirectUri = null,
        string $tenant = 'common',
    ): ProviderConfig {
        $base = 'https://login.microsoftonline.com/' . $tenant;

        return new ProviderConfig(
            name: 'microsoft',
            type: 'oidc',
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizationUrl: $base . '/oauth2/v2.0/authorize',
            tokenUrl: $base . '/oauth2/v2.0/token',
            jwksUri: $base . '/discovery/v2.0/keys',
            issuer: $base . '/v2.0',
            scopes: ['openid', 'email', 'profile'],
            redirectUri: $redirectUri,
        );
    }
}
