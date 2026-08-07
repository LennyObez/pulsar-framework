<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Auth\Http\Controller\OAuth2Controller;
use Pulsar\Extension\Auth\Http\Controller\OidcDiscoveryController;
use Pulsar\Extension\Auth\Http\Controller\UserInfoController;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * Unified authentication extension.
 *
 * Consolidates social SSO (Google, GitHub, Facebook, Apple), OAuth2 authorization
 * server (authorization code, client credentials, refresh tokens), and WebAuthn/FIDO2
 * passwordless authentication into a single cohesive extension.
 *
 * Replaces: pulsar/social-sso, pulsar/oauth2, pulsar/webauthn
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
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

    /**
     * Register the HTTP surface this extension can serve end to end.
     *
     * Every route registered here resolves to a controller the router can
     * invoke. A route the extension cannot serve is not registered at all:
     * a path that answers 500 advertises an endpoint that does not exist,
     * which is worse than a 404.
     */
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // OAuth2/OIDC authorization server endpoints
        $router->group('/oauth', static function (RouterInterface $router): void {
            $router->get('/authorize', [OAuth2Controller::class, 'authorize'], 'oauth2.authorize');
            $router->post('/token', [OAuth2Controller::class, 'token'], 'oauth2.token');
            $router->post('/introspect', [OAuth2Controller::class, 'introspect'], 'oauth2.introspect');
            $router->post('/revoke', [OAuth2Controller::class, 'revoke'], 'oauth2.revoke');
        });

        // OIDC discovery documents: derived from config and the public half of
        // the signing keyring, so they need nothing from the host application.
        $router->get(
            '/.well-known/openid-configuration',
            [OidcDiscoveryController::class, 'configuration'],
            'oauth2.oidc.discovery',
        );
        $router->get('/.well-known/jwks.json', [OidcDiscoveryController::class, 'jwks'], 'oauth2.oidc.jwks');

        // UserInfo returns claims out of the host's user store. The extension
        // binds no default for that port — mapping a subject to claims is a
        // per-deployment decision — so the endpoint exists only once the
        // application has bound one.
        if ($container->has(UserClaimsProviderInterface::class)) {
            $router->get('/oauth/userinfo', [UserInfoController::class, '__invoke'], 'oauth2.oidc.userinfo');
        }

        // The seven /webauthn/* ceremony endpoints are deliberately NOT
        // registered. Each one needs state the extension does not own: the
        // challenge has to be held server-side against the caller's session,
        // and register/authenticate/list/rename/revoke additionally need the
        // acting user's identity plus an ownership check on the credential.
        // Applications wire those routes against their own session and user
        // store; the ceremonies themselves are reachable through
        // WebAuthnServerInterface. Note that the flow in docs/webauthn.md has
        // the client echo the challenge back in the request body, which lets
        // it choose its own — do not copy it.

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
