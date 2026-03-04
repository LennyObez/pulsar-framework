<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

/**
 * OAuth2/OIDC authorization server extension.
 *
 * Provides server-side OAuth2 authorization code, client credentials, and
 * refresh token flows with PKCE enforcement, OpenID Connect provider
 * capability, and token introspection/revocation endpoints.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
final class OAuth2Extension implements ExtensionInterface
{
    /**
     * F385.13: every credential-bearing OAuth2 endpoint runs the
     * `rate-limit` middleware so an attacker cannot DoS the token
     * database by spamming `/oauth/revoke`, brute-force client
     * authentication via `/oauth/token`, or fingerprint via
     * `/oauth/introspect`. The rate-limit alias is registered in
     * `MiddlewareAliasConfig::defaultAliases()`.
     */
    private const array RATE_LIMITED_MIDDLEWARE = ['rate-limit'];

    public function name(): string
    {
        return 'pulsar/oauth2';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // OAuth2 endpoints. Token, introspect, and revoke routes carry
        // the `rate-limit` middleware (F385.13) so they cannot be
        // weaponised for DoS or credential brute-force. /oauth/authorize
        // is exempt because the rate-limit there belongs to the upstream
        // session / login flow and applying it twice creates spurious
        // 429s on legitimate user redirects.
        $router->get('/oauth/authorize', 'oauth2.authorize');
        $router->add(new Route(
            methods: [Method::POST],
            path: '/oauth/token',
            handler: 'oauth2.token',
            middleware: self::RATE_LIMITED_MIDDLEWARE,
        ));
        $router->add(new Route(
            methods: [Method::POST],
            path: '/oauth/introspect',
            handler: 'oauth2.introspect',
            middleware: self::RATE_LIMITED_MIDDLEWARE,
        ));
        $router->add(new Route(
            methods: [Method::POST],
            path: '/oauth/revoke',
            handler: 'oauth2.revoke',
            middleware: self::RATE_LIMITED_MIDDLEWARE,
        ));

        // OIDC endpoints
        $router->get('/.well-known/openid-configuration', 'oauth2.oidc.discovery');
        $router->get('/.well-known/jwks.json', 'oauth2.oidc.jwks');
        $router->get('/oauth/userinfo', 'oauth2.oidc.userinfo');
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            OAuth2ServiceProvider::class,
        ];
    }
}
