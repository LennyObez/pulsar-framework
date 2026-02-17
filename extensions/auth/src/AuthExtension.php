<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * Unified authentication extension.
 *
 * Consolidates social SSO (Google, GitHub, Facebook, Apple), OAuth2 authorization
 * server (authorization code, client credentials, refresh tokens), and WebAuthn/FIDO2
 * passwordless authentication into a single cohesive extension.
 *
 * Replaces: pulsar/social-sso, pulsar/oauth2, pulsar/webauthn
 */
final class AuthExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/auth';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // OAuth2/OIDC authorization server endpoints
        $router->group('/oauth', function (RouterInterface $router): void {
            $router->get('/authorize', 'oauth2.authorize');
            $router->post('/token', 'oauth2.token');
            $router->post('/introspect', 'oauth2.introspect');
            $router->post('/revoke', 'oauth2.revoke');
        });

        // OIDC discovery and JWKS endpoints
        $router->get('/.well-known/openid-configuration', 'oauth2.oidc.discovery');
        $router->get('/.well-known/jwks.json', 'oauth2.oidc.jwks');
        $router->get('/oauth/userinfo', 'oauth2.oidc.userinfo');

        // WebAuthn ceremony endpoints
        $router->group('/webauthn', function (RouterInterface $router): void {
            // Registration (attestation) ceremony
            $router->post('/register/options', 'webauthn.register.options');
            $router->post('/register/verify', 'webauthn.register.verify');

            // Authentication (assertion) ceremony
            $router->post('/authenticate/options', 'webauthn.authenticate.options');
            $router->post('/authenticate/verify', 'webauthn.authenticate.verify');

            // Authenticator management
            $router->get('/authenticators', 'webauthn.authenticators.list');
            $router->put('/authenticators/{id}/rename', 'webauthn.authenticators.rename');
            $router->delete('/authenticators/{id}', 'webauthn.authenticators.revoke');
        });

        // Social SSO routes are registered by the application using the SSO config routes
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }
}
