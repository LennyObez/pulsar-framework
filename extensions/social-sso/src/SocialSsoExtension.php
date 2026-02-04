<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * Social SSO extension for OAuth-based single sign-on.
 *
 * Provides vendor-agnostic social authentication with PKCE, nonce verification,
 * JWKS-based ID token validation, and pluggable identity linking.
 */
final class SocialSsoExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/social-sso';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // Routes are registered by the service provider or manually by the app
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            SocialSsoServiceProvider::class,
        ];
    }
}
