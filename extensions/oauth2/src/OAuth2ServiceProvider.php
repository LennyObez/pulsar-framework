<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\OAuth2\Adapter\LeagueAuthorizationServer;
use Pulsar\Extension\OAuth2\Client\InMemoryClientRepository;
use Pulsar\Extension\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\OAuth2\Consent\InMemoryConsentRepository;
use Pulsar\Extension\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\AuthorizationCodeRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\AuthorizationServerInterface;
use Pulsar\Extension\OAuth2\Contract\ClientRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\ConsentRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\OAuth2\Grant\AuthorizationCodeGrant;
use Pulsar\Extension\OAuth2\Grant\ClientCredentialsGrant;
use Pulsar\Extension\OAuth2\Grant\RefreshTokenGrant;
use Pulsar\Extension\OAuth2\Oidc\IdTokenBuilder;
use Pulsar\Extension\OAuth2\Oidc\JwksEndpoint;
use Pulsar\Extension\OAuth2\Oidc\JwtSigner;
use Pulsar\Extension\OAuth2\Oidc\OidcConfig;
use Pulsar\Extension\OAuth2\Oidc\OidcDiscovery;
use Pulsar\Extension\OAuth2\Oidc\UserInfoEndpoint;
use Pulsar\Extension\OAuth2\Token\InMemoryAccessTokenRepository;
use Pulsar\Extension\OAuth2\Token\InMemoryAuthorizationCodeRepository;
use Pulsar\Extension\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\OAuth2\Token\InMemoryScopeRepository;
use Pulsar\Security\Crypto\KeyRingInterface;

/**
 * Service provider for the OAuth2/OIDC extension.
 *
 * Binds all OAuth2 services to the container: repositories, grant handlers,
 * authorization server, OIDC components, and the JWT signer.
 */
final class OAuth2ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Configuration
        $container->bind(OAuth2Config::class, static function () use ($container): OAuth2Config {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.oauth2')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.oauth2');
            }

            return OAuth2Config::fromArray($configData);
        });

        $container->bind(OidcConfig::class, static function () use ($container): OidcConfig {
            /** @var OAuth2Config $oauth2Config */
            $oauth2Config = $container->get(OAuth2Config::class);

            return new OidcConfig(
                issuer: $oauth2Config->issuer,
                signingAlgorithms: $oauth2Config->signingAlgorithms,
                pairwiseSubjects: $oauth2Config->pairwiseSubjects,
                signingKeyId: $oauth2Config->signingKeyId,
            );
        });

        // Repositories (in-memory defaults — applications override with persistent implementations)
        $container->bind(ClientRepositoryInterface::class, InMemoryClientRepository::class);
        $container->bind(ScopeRepositoryInterface::class, InMemoryScopeRepository::class);
        $container->bind(ConsentRepositoryInterface::class, InMemoryConsentRepository::class);
        $container->bind(AccessTokenRepositoryInterface::class, InMemoryAccessTokenRepository::class);
        $container->bind(RefreshTokenRepositoryInterface::class, InMemoryRefreshTokenRepository::class);
        $container->bind(AuthorizationCodeRepositoryInterface::class, InMemoryAuthorizationCodeRepository::class);

        // JWT signing
        $container->bind(JwtSigner::class, static function () use ($container): JwtSigner {
            /** @var KeyRingInterface $keyRing */
            $keyRing = $container->get(KeyRingInterface::class);

            return new JwtSigner($keyRing);
        });

        // Grant handlers
        $container->bind(AuthorizationCodeGrant::class, static function () use ($container): AuthorizationCodeGrant {
            return new AuthorizationCodeGrant(
                $container->get(AuthorizationCodeRepositoryInterface::class),
                $container->get(AccessTokenRepositoryInterface::class),
                $container->get(RefreshTokenRepositoryInterface::class),
                $container->get(AuditLoggerInterface::class),
            );
        });

        $container->bind(ClientCredentialsGrant::class, static function () use ($container): ClientCredentialsGrant {
            return new ClientCredentialsGrant(
                $container->get(AccessTokenRepositoryInterface::class),
                $container->get(AuditLoggerInterface::class),
                $container->get(OAuth2Config::class),
            );
        });

        $container->bind(RefreshTokenGrant::class, static function () use ($container): RefreshTokenGrant {
            return new RefreshTokenGrant(
                $container->get(RefreshTokenRepositoryInterface::class),
                $container->get(AccessTokenRepositoryInterface::class),
                $container->get(AuditLoggerInterface::class),
            );
        });

        // Authorization server
        $container->bind(AuthorizationServerInterface::class, static function () use ($container): AuthorizationServerInterface {
            return new LeagueAuthorizationServer(
                $container->get(ClientRepositoryInterface::class),
                $container->get(ScopeRepositoryInterface::class),
                $container->get(ConsentRepositoryInterface::class),
                $container->get(AuthorizationCodeGrant::class),
                $container->get(ClientCredentialsGrant::class),
                $container->get(RefreshTokenGrant::class),
                $container->get(AccessTokenRepositoryInterface::class),
                $container->get(RefreshTokenRepositoryInterface::class),
                $container->get(AuditLoggerInterface::class),
                $container->get(OAuth2Config::class),
            );
        });

        // OIDC components
        $container->bind(OidcDiscovery::class, static function () use ($container): OidcDiscovery {
            return new OidcDiscovery($container->get(OidcConfig::class));
        });

        $container->bind(JwksEndpoint::class, static function () use ($container): JwksEndpoint {
            return new JwksEndpoint(
                $container->get(KeyRingInterface::class),
            );
        });

        $container->bind(IdTokenBuilder::class, static function () use ($container): IdTokenBuilder {
            return new IdTokenBuilder(
                $container->get(OidcConfig::class),
                $container->get(UserClaimsProviderInterface::class),
                $container->get(JwtSigner::class),
            );
        });

        $container->bind(UserInfoEndpoint::class, static function () use ($container): UserInfoEndpoint {
            return new UserInfoEndpoint(
                $container->get(UserClaimsProviderInterface::class),
                $container->get(AccessTokenRepositoryInterface::class),
            );
        });
    }

    public function provides(): array
    {
        return [
            OAuth2Config::class,
            OidcConfig::class,
            ClientRepositoryInterface::class,
            ScopeRepositoryInterface::class,
            ConsentRepositoryInterface::class,
            AccessTokenRepositoryInterface::class,
            RefreshTokenRepositoryInterface::class,
            AuthorizationCodeRepositoryInterface::class,
            AuthorizationServerInterface::class,
            JwtSigner::class,
            AuthorizationCodeGrant::class,
            ClientCredentialsGrant::class,
            RefreshTokenGrant::class,
            OidcDiscovery::class,
            JwksEndpoint::class,
            IdTokenBuilder::class,
            UserInfoEndpoint::class,
        ];
    }
}
