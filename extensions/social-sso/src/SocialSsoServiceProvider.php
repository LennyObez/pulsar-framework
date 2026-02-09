<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\SocialSso\Config\SocialSsoConfig;
use Pulsar\Extension\SocialSso\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\JwtSignatureDriverInterface;
use Pulsar\Extension\SocialSso\Contracts\NonceVerifierInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\SocialSso\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\SocialSso\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\SocialSso\Contracts\SsoGatewayInterface;
use Pulsar\Extension\SocialSso\Gateway\SsoGateway;
use Pulsar\Extension\SocialSso\Internal\Linker\NullSocialIdentityLinker;
use Pulsar\Extension\SocialSso\Internal\Provider\OAuthProviderRegistry;
use Pulsar\Extension\SocialSso\Internal\Session\SessionNonceVerifier;
use Pulsar\Extension\SocialSso\Internal\Session\SessionOAuthStateManager;
use Pulsar\Extension\SocialSso\Internal\Token\JwksFetcher;
use Pulsar\Extension\SocialSso\Internal\Token\JwksIdTokenVerifier;
use Pulsar\Extension\SocialSso\Internal\Token\OpenSslJwtSignatureDriver;
use Pulsar\Security\Session\SessionInterface;

/**
 * Service provider for the social SSO extension.
 *
 * Binds all SSO services to the container: OAuth provider registry, state management,
 * nonce verification, JWT/JWKS verification, identity linking, and the SSO gateway.
 */
final class SocialSsoServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(SocialSsoConfig::class, static function () use ($container): SocialSsoConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.social_sso')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.social_sso');
            }

            return SocialSsoConfig::fromArray($configData);
        });

        // OAuth provider registry
        $container->bind(OAuthProviderRegistryInterface::class, OAuthProviderRegistry::class);

        // OAuth state manager (session-backed)
        $container->bind(OAuthStateManagerInterface::class, static function () use ($container): OAuthStateManagerInterface {
            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);

            /** @var SocialSsoConfig $config */
            $config = $container->get(SocialSsoConfig::class);

            return new SessionOAuthStateManager($session, $config->stateTtlSeconds);
        });

        // Nonce verifier (session-backed)
        $container->bind(NonceVerifierInterface::class, static function () use ($container): NonceVerifierInterface {
            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);

            return new SessionNonceVerifier($session);
        });

        // JWT signature driver
        $container->bind(JwtSignatureDriverInterface::class, OpenSslJwtSignatureDriver::class);

        // JWKS fetcher
        $container->bind(JwksFetcher::class, JwksFetcher::class);

        // ID token verifier (JWKS-based)
        $container->bind(IdTokenVerifierInterface::class, JwksIdTokenVerifier::class);

        // Social identity linker (null default — apps override with their own implementation)
        $container->bind(SocialIdentityLinkerInterface::class, NullSocialIdentityLinker::class);

        // SSO gateway
        $container->bind(SsoGateway::class, SsoGateway::class);
        $container->bind(SsoGatewayInterface::class, SsoGateway::class);
    }

    public function provides(): array
    {
        return [
            SocialSsoConfig::class,
            OAuthProviderRegistryInterface::class,
            OAuthStateManagerInterface::class,
            NonceVerifierInterface::class,
            JwtSignatureDriverInterface::class,
            JwksFetcher::class,
            IdTokenVerifierInterface::class,
            SocialIdentityLinkerInterface::class,
            SsoGateway::class,
            SsoGatewayInterface::class,
        ];
    }
}
