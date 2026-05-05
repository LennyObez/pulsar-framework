<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Auth\Config\AuthConfig;
use Pulsar\Extension\Auth\OAuth2\Adapter\OAuth2AuthorizationServer;
use Pulsar\Extension\Auth\OAuth2\Client\InMemoryClientRepository;
use Pulsar\Extension\Auth\OAuth2\Config\OAuth2Config;
use Pulsar\Extension\Auth\OAuth2\Consent\InMemoryConsentRepository;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationCodeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\AuthorizationServerInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ClientRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ConsentRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\RefreshTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\ScopeRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\Auth\OAuth2\Grant\AuthorizationCodeGrant;
use Pulsar\Extension\Auth\OAuth2\Grant\ClientCredentialsGrant;
use Pulsar\Extension\Auth\OAuth2\Grant\RefreshTokenGrant;
use Pulsar\Extension\Auth\OAuth2\Oidc\IdTokenBuilder;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwksEndpoint;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwtSigner;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcConfig;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcDiscovery;
use Pulsar\Extension\Auth\OAuth2\Oidc\UserInfoEndpoint;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Auth\OAuth2\Token\DbAuthorizationCodeRepository;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryAccessTokenRepository;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryAuthorizationCodeRepository;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryRefreshTokenRepository;
use Pulsar\Extension\Auth\OAuth2\Token\InMemoryScopeRepository;
use Pulsar\Extension\Auth\Social\Config\SocialSsoConfig;
use Pulsar\Extension\Auth\Social\Contracts\IdTokenVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\JwtSignatureDriverInterface;
use Pulsar\Extension\Auth\Social\Contracts\NonceVerifierInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;
use Pulsar\Extension\Auth\Social\Contracts\OAuthStateManagerInterface;
use Pulsar\Extension\Auth\Social\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\Auth\Social\Contracts\SsoGatewayInterface;
use Pulsar\Extension\Auth\Social\Gateway\SsoGateway;
use Pulsar\Extension\Auth\Social\Internal\Linker\NullSocialIdentityLinker;
use Pulsar\Extension\Auth\Social\Internal\Provider\OAuthProviderRegistry;
use Pulsar\Extension\Auth\Social\Internal\Session\SessionNonceVerifier;
use Pulsar\Extension\Auth\Social\Internal\Session\SessionOAuthStateManager;
use Pulsar\Extension\Auth\Social\Internal\Token\JwksFetcher;
use Pulsar\Extension\Auth\Social\Internal\Token\JwksIdTokenVerifier;
use Pulsar\Extension\Auth\Social\Internal\Token\OpenSslJwtSignatureDriver;
use Pulsar\Extension\Auth\WebAuthn\Adapter\AttestationVerifier;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryAuthenticatorRepository;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\Auth\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Session\SessionInterface;

/**
 * Unified service provider for the auth extension.
 *
 * Registers all services for social SSO, OAuth2 authorization server,
 * and WebAuthn/FIDO2 into the container.
 */
final class AuthServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $this->registerConfig($container);
        $this->registerSocialSso($container);
        $this->registerOAuth2($container);
        $this->registerWebAuthn($container);
    }

    public function provides(): array
    {
        return [
            // Config
            AuthConfig::class,
            SocialSsoConfig::class,
            OAuth2Config::class,
            OidcConfig::class,
            WebAuthnConfig::class,

            // Social SSO
            OAuthProviderRegistryInterface::class,
            OAuthStateManagerInterface::class,
            NonceVerifierInterface::class,
            JwtSignatureDriverInterface::class,
            JwksFetcher::class,
            IdTokenVerifierInterface::class,
            SocialIdentityLinkerInterface::class,
            SsoGateway::class,
            SsoGatewayInterface::class,

            // OAuth2
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

            // WebAuthn
            CredentialRepositoryInterface::class,
            AuthenticatorRepositoryInterface::class,
            AttestationVerifierInterface::class,
            RegistrationCeremony::class,
            AuthenticationCeremony::class,
            WebAuthnServerInterface::class,
            WebAuthnServer::class,
        ];
    }

    private function registerConfig(ContainerInterface $container): void
    {
        $container->bind(AuthConfig::class, static function () use ($container): AuthConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.auth')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.auth');
            }

            return AuthConfig::fromArray($configData);
        });

        $container->bind(SocialSsoConfig::class, static function () use ($container): SocialSsoConfig {
            /** @var AuthConfig $authConfig */
            $authConfig = $container->get(AuthConfig::class);

            return $authConfig->social;
        });

        $container->bind(OAuth2Config::class, static function () use ($container): OAuth2Config {
            /** @var AuthConfig $authConfig */
            $authConfig = $container->get(AuthConfig::class);

            return $authConfig->oauth2;
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

        $container->bind(WebAuthnConfig::class, static function () use ($container): WebAuthnConfig {
            /** @var AuthConfig $authConfig */
            $authConfig = $container->get(AuthConfig::class);

            return $authConfig->webauthn;
        });
    }

    private function registerSocialSso(ContainerInterface $container): void
    {
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

        // Social identity linker (null default; apps override with their own implementation)
        $container->bind(SocialIdentityLinkerInterface::class, NullSocialIdentityLinker::class);

        // SSO gateway
        $container->bind(SsoGateway::class, SsoGateway::class);
        $container->bind(SsoGatewayInterface::class, SsoGateway::class);
    }

    private function registerOAuth2(ContainerInterface $container): void
    {
        // Repositories (in-memory defaults; applications override with persistent implementations)
        $container->bind(ClientRepositoryInterface::class, InMemoryClientRepository::class);
        $container->bind(ScopeRepositoryInterface::class, InMemoryScopeRepository::class);
        $container->bind(ConsentRepositoryInterface::class, InMemoryConsentRepository::class);
        $container->bind(AccessTokenRepositoryInterface::class, InMemoryAccessTokenRepository::class);
        $container->bind(RefreshTokenRepositoryInterface::class, InMemoryRefreshTokenRepository::class);
        // F385.12: production deployments select `database` so codes survive
        // worker restarts; in-memory remains the dev/test default.
        $container->bind(AuthorizationCodeRepositoryInterface::class, static function () use ($container): AuthorizationCodeRepositoryInterface {
            /** @var OAuth2Config $config */
            $config = $container->get(OAuth2Config::class);

            if ($config->authorizationCodeStore === 'database' && $container->has(ConnectionInterface::class)) {
                /** @var ConnectionInterface $connection */
                $connection = $container->get(ConnectionInterface::class);
                $repo = new DbAuthorizationCodeRepository($connection);
                $repo->installSchema();

                return $repo;
            }

            return new InMemoryAuthorizationCodeRepository();
        });

        // JWT signing (RS256 via KeyRing-managed RSA private keys)
        $container->bind(JwtSigner::class, static function () use ($container): JwtSigner {
            /** @var KeyRingInterface $keyRing */
            $keyRing = $container->get(KeyRingInterface::class);
            /** @var OidcConfig $oidcConfig */
            $oidcConfig = $container->get(OidcConfig::class);

            return new JwtSigner($keyRing, $oidcConfig);
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
                $container->get(ScopeRepositoryInterface::class),
                $container->get(AuditLoggerInterface::class),
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
            return new OAuth2AuthorizationServer(
                $container->get(ClientRepositoryInterface::class),
                $container->get(ScopeRepositoryInterface::class),
                $container->get(ConsentRepositoryInterface::class),
                $container->get(AccessTokenRepositoryInterface::class),
                $container->get(RefreshTokenRepositoryInterface::class),
                $container->get(AuthorizationCodeGrant::class),
                $container->get(ResponseFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
                $container->get(AuditLoggerInterface::class),
                [$container->get(ClientCredentialsGrant::class), $container->get(RefreshTokenGrant::class)],
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

    private function registerWebAuthn(ContainerInterface $container): void
    {
        // Credential repository (in-memory default; apps override with persistent implementation)
        $container->bind(CredentialRepositoryInterface::class, InMemoryCredentialRepository::class);

        // Authenticator repository (in-memory default; apps override with persistent implementation)
        $container->bind(AuthenticatorRepositoryInterface::class, InMemoryAuthenticatorRepository::class);

        // Attestation verifier
        $container->bind(AttestationVerifierInterface::class, static function () use ($container): AttestationVerifierInterface {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);

            return new AttestationVerifier($config->allowedFormats);
        });

        // Registration ceremony
        $container->bind(RegistrationCeremony::class, static function () use ($container): RegistrationCeremony {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);
            /** @var AttestationVerifierInterface $attestationVerifier */
            $attestationVerifier = $container->get(AttestationVerifierInterface::class);
            /** @var CredentialRepositoryInterface $credentialRepository */
            $credentialRepository = $container->get(CredentialRepositoryInterface::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);

            return new RegistrationCeremony($config, $attestationVerifier, $credentialRepository, $auditLogger);
        });

        // Authentication ceremony
        $container->bind(AuthenticationCeremony::class, static function () use ($container): AuthenticationCeremony {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);
            /** @var CredentialRepositoryInterface $credentialRepository */
            $credentialRepository = $container->get(CredentialRepositoryInterface::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);

            return new AuthenticationCeremony($config, $credentialRepository, $auditLogger);
        });

        // WebAuthn server (top-level orchestrator)
        $container->bind(WebAuthnServerInterface::class, static function () use ($container): WebAuthnServerInterface {
            /** @var RegistrationCeremony $registrationCeremony */
            $registrationCeremony = $container->get(RegistrationCeremony::class);
            /** @var AuthenticationCeremony $authenticationCeremony */
            $authenticationCeremony = $container->get(AuthenticationCeremony::class);

            return new WebAuthnServer($registrationCeremony, $authenticationCeremony);
        });

        $container->bind(WebAuthnServer::class, static function () use ($container): WebAuthnServer {
            /** @var WebAuthnServer */
            return $container->get(WebAuthnServerInterface::class);
        });
    }
}
