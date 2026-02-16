<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * OAuth2/OIDC authorization server extension.
 *
 * Provides server-side OAuth2 authorization code, client credentials, and
 * refresh token flows with PKCE enforcement, OpenID Connect provider
 * capability, and token introspection/revocation endpoints.
 */
final class OAuth2Extension implements ExtensionInterface
{
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
        // Register OAuth2/OIDC endpoints
        $router->group('/oauth', function (RouterInterface $router) use ($container): void {
            $router->get('/authorize', 'oauth2.authorize');
            $router->post('/token', 'oauth2.token');
            $router->post('/introspect', 'oauth2.introspect');
            $router->post('/revoke', 'oauth2.revoke');
        });

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
