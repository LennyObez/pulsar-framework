<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Auth\Config\AuthConfig;
use Pulsar\Extension\Auth\Http\Controller\OAuth2Controller;
use Pulsar\Extension\Auth\Http\Controller\OidcDiscoveryController;
use Pulsar\Extension\Auth\Http\Controller\UserInfoController;
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
use Pulsar\Extension\Auth\OAuth2\Oidc\JwksEndpointInterface;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwtSigner;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcConfig;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcDiscovery;
use Pulsar\Extension\Auth\OAuth2\Oidc\UserInfoEndpoint;
use Pulsar\Extension\Auth\OAuth2\Oidc\UserInfoEndpointInterface;
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
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryChallengeStore;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryCredentialRepository;
use Pulsar\Extension\Auth\WebAuthn\Adapter\WebAuthnServer;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\AuthenticationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\RegistrationCeremony;
use Pulsar\Extension\Auth\WebAuthn\Config\WebAuthnConfig;
use Pulsar\Extension\Auth\WebAuthn\Contract\AttestationVerifierInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\ChallengeStoreInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\Auth\WebAuthn\Contract\WebAuthnServerInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Session\SessionInterface;
use RuntimeException;

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
        $this->registerControllers($container);
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
            JwksEndpointInterface::class,
            IdTokenBuilder::class,
            UserInfoEndpoint::class,
            UserInfoEndpointInterface::class,

            // WebAuthn
            CredentialRepositoryInterface::class,
            ChallengeStoreInterface::class,
            AuthenticatorRepositoryInterface::class,
            AttestationVerifierInterface::class,
            RegistrationCeremony::class,
            AuthenticationCeremony::class,
            WebAuthnServerInterface::class,
            WebAuthnServer::class,

            // Route handlers
            OAuth2Controller::class,
            OidcDiscoveryController::class,
            UserInfoController::class,
        ];
    }

    private function registerConfig(ContainerInterface $container): void
    {
        $container->bind(AuthConfig::class, static function () use ($container): AuthConfig {
            $configData = $container->has(ExtensionConfigRegistry::class)
                ? $container->get(ExtensionConfigRegistry::class)->section('auth')
                : [];

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
        // Production deployments select `database` so codes survive worker
        // restarts; in-memory remains the dev/test default.
        $container->bind(AuthorizationCodeRepositoryInterface::class, static function () use ($container): AuthorizationCodeRepositoryInterface {
            /** @var OAuth2Config $config */
            $config = $container->get(OAuth2Config::class);

            if ($config->authorizationCodeStore !== 'database') {
                return new InMemoryAuthorizationCodeRepository();
            }

            // `database` is an explicit operator choice, so a missing
            // dependency stops boot instead of silently downgrading to a
            // store that loses every code on worker restart.
            if (!$container->has(ConnectionInterface::class)) {
                throw new RuntimeException(
                    'OAuth2 authorization_code_store is "database" but no '
                    . ConnectionInterface::class . ' is bound.',
                );
            }

            if (!$container->has(KeyProviderInterface::class)) {
                throw new RuntimeException(
                    'OAuth2 authorization_code_store is "database" but no '
                    . KeyProviderInterface::class . ' is bound; the code lookup '
                    . 'digest is keyed from the master key (set PULSAR_MASTER_KEY).',
                );
            }

            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            /** @var KeyProviderInterface $keyProvider */
            $keyProvider = $container->get(KeyProviderInterface::class);

            $repo = new DbAuthorizationCodeRepository($connection, $keyProvider);
            $repo->installSchema();

            return $repo;
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

        // The controller resolves the contract. Delegating rather than aliasing to the
        // class keeps the factory above the single place the key ring is read from.
        $container->bind(JwksEndpointInterface::class, static function () use ($container): JwksEndpointInterface {
            /** @var JwksEndpoint $endpoint */
            $endpoint = $container->get(JwksEndpoint::class);

            return $endpoint;
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

        // Same delegation: the userinfo route is only bound once an application supplies
        // a claims provider, and that decision must stay in the factory above.
        $container->bind(UserInfoEndpointInterface::class, static function () use ($container): UserInfoEndpointInterface {
            /** @var UserInfoEndpoint $endpoint */
            $endpoint = $container->get(UserInfoEndpoint::class);

            return $endpoint;
        });
    }

    /**
     * Resolve the audit logger for a WebAuthn service, or refuse to build it.
     *
     * WebAuthn deliberately does NOT follow the degrade-to-null pattern the
     * `src/Core/Wiring/*` classes use for mail, notifications, workflow and data
     * purge. Those subsystems lose a record when the logger is absent; this one
     * would lose the record of who proved possession of a second factor, who
     * registered one, and who took one away. The framework already draws that
     * line: {@see \Pulsar\Core\Wiring\ZeroTrustWiring} refuses to wire
     * enforcement at all without an audit logger, on the reasoning that a
     * control which cannot record its decisions must not make them. WebAuthn
     * sits on the same side of the line — SOC 2 CC6.1/CC7.2, ISO/IEC 27001
     * A.8.15 and HIPAA §164.312(b) all treat authentication events as records
     * that must exist — with one difference in mechanism: zero trust is
     * middleware an application can simply not have, whereas
     * `WebAuthnServerInterface` is asked for by name, so returning nothing would
     * surface later as an unrelated "no binding" error. It fails closed here
     * instead, at the point of resolution, naming what is missing.
     *
     * A bare `$container->get()` also fails closed today, by way of
     * `NotFoundException`, but only by accident: nothing said the failure was
     * intended, so the next reader "matching the src/ pattern" would replace it
     * with a null logger and silently switch the strongest factor to unaudited.
     */
    private function requireAuditLogger(ContainerInterface $container, string $subsystem): AuditLoggerInterface
    {
        if (!$container->has(AuditLoggerInterface::class)) {
            throw new RuntimeException(
                'WebAuthn ' . $subsystem . ' cannot be built: no '
                . AuditLoggerInterface::class . ' is bound. Second-factor '
                . 'ceremonies and authenticator revocations are auditable events, '
                . 'so the subsystem fails closed rather than authenticate or '
                . 'remove a factor with no entry in the audit chain.',
            );
        }

        /** @var AuditLoggerInterface */
        return $container->get(AuditLoggerInterface::class);
    }

    /**
     * The clock the challenge window is measured against.
     *
     * Unlike the audit logger this one degrades, and the difference is not
     * inconsistency: an absent clock binding costs nothing, because the system
     * clock is a complete and correct implementation of what the ceremony needs.
     * The binding exists only so a deployment can substitute a monotonic or
     * skew-corrected source, and so tests can freeze time. An absent audit
     * logger has no such stand-in.
     */
    private function optionalClock(ContainerInterface $container): ?ClockInterface
    {
        return self::optionalClockOf($container);
    }

    /**
     * The same resolution, reachable from a static binding closure.
     *
     * The challenge store is bound through a `static function`, which has no
     * `$this`; duplicating the lookup inside it would leave two places to change
     * when the clock binding moves.
     */
    private static function optionalClockOf(ContainerInterface $container): ?ClockInterface
    {
        if (!$container->has(ClockInterface::class)) {
            return null;
        }

        /** @var ClockInterface */
        return $container->get(ClockInterface::class);
    }

    private function registerWebAuthn(ContainerInterface $container): void
    {
        // Credential repository (in-memory default; apps override with persistent implementation)
        $container->bind(CredentialRepositoryInterface::class, InMemoryCredentialRepository::class);

        // Authenticator repository (in-memory default; apps override with persistent implementation).
        // Bound through a factory rather than by class name because the repository
        // now audits every mutation and autowiring cannot state the fail-closed
        // requirement on the logger.
        $container->bind(AuthenticatorRepositoryInterface::class, function () use ($container): AuthenticatorRepositoryInterface {
            return new InMemoryAuthenticatorRepository(
                $this->requireAuditLogger($container, 'authenticator repository'),
            );
        });

        // Spent-challenge store. Bound as a singleton, which is load-bearing rather
        // than incidental: a per-resolution store answers "not spent" to every
        // ceremony and turns single-use back off without any signal that it did.
        //
        // The in-memory default is single-process only. A deployment running more
        // than one worker MUST rebind this to a shared implementation (Redis
        // `SET NX`, a unique index on a challenge column) or a replay routed to a
        // second worker is admitted. The default is not a placeholder: it is the
        // same posture the credential and authenticator repositories take, a
        // complete implementation of the port for the deployment shape it names.
        $container->bind(ChallengeStoreInterface::class, static function () use ($container): ChallengeStoreInterface {
            return new InMemoryChallengeStore(self::optionalClockOf($container));
        });

        // Attestation verifier
        $container->bind(AttestationVerifierInterface::class, static function () use ($container): AttestationVerifierInterface {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);

            return new AttestationVerifier($config->allowedFormats);
        });

        // Registration ceremony
        $container->bind(RegistrationCeremony::class, function () use ($container): RegistrationCeremony {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);
            /** @var AttestationVerifierInterface $attestationVerifier */
            $attestationVerifier = $container->get(AttestationVerifierInterface::class);
            /** @var CredentialRepositoryInterface $credentialRepository */
            $credentialRepository = $container->get(CredentialRepositoryInterface::class);
            $auditLogger = $this->requireAuditLogger($container, 'registration ceremony');

            /** @var ChallengeStoreInterface $challengeStore */
            $challengeStore = $container->get(ChallengeStoreInterface::class);

            return new RegistrationCeremony(
                $config,
                $attestationVerifier,
                $credentialRepository,
                $auditLogger,
                $this->optionalClock($container),
                $challengeStore,
            );
        });

        // Authentication ceremony
        $container->bind(AuthenticationCeremony::class, function () use ($container): AuthenticationCeremony {
            /** @var WebAuthnConfig $config */
            $config = $container->get(WebAuthnConfig::class);
            /** @var CredentialRepositoryInterface $credentialRepository */
            $credentialRepository = $container->get(CredentialRepositoryInterface::class);
            $auditLogger = $this->requireAuditLogger($container, 'authentication ceremony');

            /** @var ChallengeStoreInterface $challengeStore */
            $challengeStore = $container->get(ChallengeStoreInterface::class);

            return new AuthenticationCeremony(
                $config,
                $credentialRepository,
                $auditLogger,
                $this->optionalClock($container),
                $challengeStore,
            );
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

    /**
     * Bind the route handlers registered by {@see AuthExtension::boot()}.
     *
     * `UserInfoController` is bound unconditionally but only routed when a
     * `UserClaimsProviderInterface` exists, so the binding stays inert in
     * deployments that do not expose UserInfo.
     */
    private function registerControllers(ContainerInterface $container): void
    {
        $container->bind(OAuth2Controller::class, OAuth2Controller::class);
        $container->bind(OidcDiscoveryController::class, OidcDiscoveryController::class);
        $container->bind(UserInfoController::class, UserInfoController::class);
    }
}
