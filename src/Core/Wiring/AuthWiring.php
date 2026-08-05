<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Guard\TokenGuard;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Internal\Persistence\DatabaseRecoveryCodeStore;
use Pulsar\Auth\Internal\Persistence\DatabaseTotpReplayGuard;
use Pulsar\Auth\Internal\Persistence\DatabaseTotpSecretStore;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\Middleware\StepUpMiddleware;
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\Password\PasswordHasher;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Auth\TwoFactor\AuthEventCollectorInterface;
use Pulsar\Auth\TwoFactor\InMemoryRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\InMemoryTotpSecretStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeHasher;
use Pulsar\Auth\TwoFactor\RecoveryCodeStoreInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpReplayGuardInterface;
use Pulsar\Auth\TwoFactor\TotpSecretStoreInterface;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Auth\TwoFactor\TwoFactorRateLimiterInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SecurityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\SessionInterface;
use Random\Randomizer;

#[Internal]
final readonly class AuthWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        /** @var SecurityConfig $securityConfig */
        $securityConfig = $configManager->repository()->get(SecurityConfig::class);

        if ($securityConfig->auth === null) {
            return;
        }

        $authConfig = $securityConfig->auth;

        // Password hasher
        $passwordHasher = new PasswordHasher();
        $container->instance(PasswordHasher::class, $passwordHasher);
        $container->instance(PasswordHasherInterface::class, $passwordHasher);

        // Auth manager
        $authManager = new AuthManager($authConfig->defaultGuard);

        // Session guard
        $session = null;
        if ($container->has(SessionInterface::class)) {
            /** @var SessionInterface $session */
            $session = $container->get(SessionInterface::class);
            $sessionGuard = new SessionGuard($session);
            $container->instance(SessionGuard::class, $sessionGuard);
            $authManager->addGuard($sessionGuard);
        }

        // Token guard (only if a TokenResolverInterface is bound)
        if ($container->has(TokenResolverInterface::class)) {
            /** @var TokenResolverInterface $tokenResolver */
            $tokenResolver = $container->get(TokenResolverInterface::class);
            $tokenGuard = new TokenGuard($tokenResolver);
            $container->instance(TokenGuard::class, $tokenGuard);
            $authManager->addGuard($tokenGuard);
        }

        $container->instance(AuthManager::class, $authManager);
        $container->instance(AuthManagerInterface::class, $authManager);

        // Role registry
        $roleRegistry = new InMemoryRoleRegistry();

        foreach ($authConfig->authorization->roles as $roleName => $roleData) {
            /** @var array<string, mixed> $roleData */
            $roleRegistry->register(Role::fromArray($roleName, $roleData));
        }

        $container->instance(InMemoryRoleRegistry::class, $roleRegistry);
        $container->instance(RoleRegistryInterface::class, $roleRegistry);

        // Gate
        $gate = new Gate($roleRegistry, $authConfig->authorization->superRoles);
        $container->instance(Gate::class, $gate);
        $container->instance(GateInterface::class, $gate);

        // Audit logger (resolve early; used by 2FA and middleware)
        // Resolve interface for 2FA manager; concrete class for auth middleware
        $auditLoggerInterface = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;
        /** @var AuditLoggerInterface|null $auditLoggerInterface */
        $auditLogger = $container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null;
        /** @var AuditLogger|null $auditLogger */
        $auditLoggerForTwoFactor = $auditLoggerInterface ?? $auditLogger;
        /** @var AuditLoggerInterface|null $auditLoggerForTwoFactor */

        // Logger for production guardrails
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;
        /** @var LoggerInterface|null $logger */

        // 2FA services
        if ($authConfig->twoFactor->enabled) {
            /** @var Randomizer $randomizer */
            $randomizer = $container->get(Randomizer::class);

            $totpGenerator = new TotpGenerator(
                codeDigits: $authConfig->twoFactor->codeDigits,
                period: $authConfig->twoFactor->codePeriod,
                randomizer: $randomizer,
            );
            $totpVerifier = new TotpVerifier($totpGenerator, $authConfig->twoFactor->verificationWindow);
            $recoveryCodeGenerator = new RecoveryCodeGenerator(
                $randomizer,
                bytesPerCode: $authConfig->twoFactor->recoveryCodeBytes,
            );
            $recoveryCodeVerifier = new RecoveryCodeVerifier();

            // Every store below is bound lazily, and the reason is the same for all three.
            //
            // Each used to pick its implementation from
            // `$container->has(ConnectionManagerInterface::class)` evaluated *here*, while
            // AuthWiring runs — which made the answer a property of where DatabaseWiring
            // sits in WiringList rather than of how the application is configured. It sat
            // behind AuthWiring, so the answer was false on every installation and the
            // whole second factor lived in process memory: an enrolment one worker
            // confirmed was unknown to the next, a consumed recovery code stayed valid
            // elsewhere, and a replayed TOTP code met an empty guard and was accepted.
            //
            // Deferring the decision to first resolution removes the ordering question
            // rather than answering it. No future tidying of WiringList can bring this
            // back, and no comment has to defend a line's position.
            $appProvidedReplayGuard = $container->has(TotpReplayGuardInterface::class);
            $appProvidedSecretStore = $container->has(TotpSecretStoreInterface::class);
            $appProvidedRecoveryStore = $container->has(RecoveryCodeStoreInterface::class);

            if (!$appProvidedReplayGuard) {
                $container->bind(
                    TotpReplayGuardInterface::class,
                    static function (ContainerInterface $c) use ($authConfig): TotpReplayGuardInterface {
                        if ($c->has(ConnectionManagerInterface::class)) {
                            /** @var ConnectionManagerInterface $connManager */
                            $connManager = $c->get(ConnectionManagerInterface::class);
                            $guard = new DatabaseTotpReplayGuard(
                                $connManager->connection(),
                                $authConfig->twoFactor->codePeriod,
                                $authConfig->twoFactor->verificationWindow,
                            );
                            $c->instance(DatabaseTotpReplayGuard::class, $guard);

                            return $guard;
                        }

                        // Both arguments are mandatory in practice: a guard that does not
                        // know the verifier's envelope forgets codes the verifier still
                        // accepts.
                        $guard = new InMemoryTotpReplayGuard(
                            $authConfig->twoFactor->codePeriod,
                            $authConfig->twoFactor->verificationWindow,
                        );
                        $c->instance(InMemoryTotpReplayGuard::class, $guard);

                        return $guard;
                    },
                );
            }

            // Secret store: use app-provided, database-backed, or fall back to in-memory.
            //
            // The encryptor is resolved inside the factory for the same reason the
            // connection is: deciding here would make the store's implementation a
            // property of when this ran, and secrets are only ever written encrypted —
            // so a master key that arrives late would silently cost persistence rather
            // than costing encryption.
            if (!$appProvidedSecretStore) {
                $container->bind(
                    TotpSecretStoreInterface::class,
                    static function (ContainerInterface $c): TotpSecretStoreInterface {
                        $totpEncryptor = null;

                        if ($c->has(MasterKey::class)) {
                            /** @var MasterKey $masterKey */
                            $masterKey = $c->get(MasterKey::class);
                            $totpEncryptor = Encryptor::fromDerivedKey($masterKey, 4, 'totpscrt');
                        }

                        if ($c->has(ConnectionManagerInterface::class) && $totpEncryptor !== null) {
                            /** @var ConnectionManagerInterface $connManager */
                            $connManager = $c->get(ConnectionManagerInterface::class);
                            $store = new DatabaseTotpSecretStore($connManager->connection(), $totpEncryptor);
                            $c->instance(DatabaseTotpSecretStore::class, $store);

                            return $store;
                        }

                        $store = new InMemoryTotpSecretStore($totpEncryptor);
                        $c->instance(InMemoryTotpSecretStore::class, $store);

                        return $store;
                    },
                );
            }

            // Recovery code hasher + store
            $recoveryCodeHasher = null;
            if ($container->has(MasterKey::class)) {
                /** @var MasterKey $masterKey */
                $masterKey = $container->get(MasterKey::class);
                $subKey = $masterKey->deriveSubKey(3, 'rcvrycod');
                $recoveryCodeHasher = new RecoveryCodeHasher($subKey);
                $container->instance(RecoveryCodeHasher::class, $recoveryCodeHasher);
            }

            if (!$appProvidedRecoveryStore) {
                $container->bind(
                    RecoveryCodeStoreInterface::class,
                    static function (ContainerInterface $c): RecoveryCodeStoreInterface {
                        if ($c->has(ConnectionManagerInterface::class)) {
                            /** @var ConnectionManagerInterface $connManager */
                            $connManager = $c->get(ConnectionManagerInterface::class);
                            $store = new DatabaseRecoveryCodeStore($connManager->connection());
                            $c->instance(DatabaseRecoveryCodeStore::class, $store);

                            return $store;
                        }

                        $store = new InMemoryRecoveryCodeStore();
                        $c->instance(InMemoryRecoveryCodeStore::class, $store);

                        return $store;
                    },
                );
            }

            $container->instance(TotpGenerator::class, $totpGenerator);
            $container->instance(TotpVerifier::class, $totpVerifier);
            $container->instance(RecoveryCodeGenerator::class, $recoveryCodeGenerator);
            $container->instance(RecoveryCodeVerifier::class, $recoveryCodeVerifier);

            // Lazy too, because it consumes all three stores: building it here would
            // resolve them here, and the deferral above would buy nothing.
            $twoFactorFactory = static function (ContainerInterface $c) use (
                $authConfig,
                $totpGenerator,
                $totpVerifier,
                $recoveryCodeGenerator,
                $recoveryCodeVerifier,
                $recoveryCodeHasher,
                $auditLoggerForTwoFactor,
                $session,
                $logger,
            ): TwoFactorManager {
                /** @var TotpReplayGuardInterface $replayGuard */
                $replayGuard = $c->get(TotpReplayGuardInterface::class);
                /** @var TotpSecretStoreInterface $secretStore */
                $secretStore = $c->get(TotpSecretStoreInterface::class);
                /** @var RecoveryCodeStoreInterface $recoveryCodeStore */
                $recoveryCodeStore = $c->get(RecoveryCodeStoreInterface::class);

                // Resolved here rather than at wire time for the same reason as the
                // stores: an application binding one of these later must still be seen.
                $rateLimiter = $c->has(TwoFactorRateLimiterInterface::class)
                    ? $c->get(TwoFactorRateLimiterInterface::class)
                    : null;
                /** @var TwoFactorRateLimiterInterface|null $rateLimiter */
                $eventCollector = $c->has(AuthEventCollectorInterface::class)
                    ? $c->get(AuthEventCollectorInterface::class)
                    : null;
                /** @var AuthEventCollectorInterface|null $eventCollector */

                // Production guardrail: warn when in-memory stores are active. It reports
                // from here because here is where the choice is finally made — and it was
                // telling the truth all along, naming all three on every boot, while the
                // database path was unreachable. TwoFactorPersistenceBootTest is the same
                // statement in a form that stops a release rather than filling a log.
                if ($logger !== null && !$authConfig->twoFactor->allowInMemory) {
                    $inMemory = [];

                    if ($secretStore instanceof InMemoryTotpSecretStore) {
                        $inMemory[] = 'InMemoryTotpSecretStore';
                    }

                    if ($recoveryCodeStore instanceof InMemoryRecoveryCodeStore) {
                        $inMemory[] = 'InMemoryRecoveryCodeStore';
                    }

                    if ($replayGuard instanceof InMemoryTotpReplayGuard) {
                        $inMemory[] = 'InMemoryTotpReplayGuard';
                    }

                    foreach ($inMemory as $store) {
                        $logger->warning(
                            "In-memory 2FA store [$store] is active: data will not persist across restarts. Bind a persistent implementation.",
                        );
                    }
                }

                return new TwoFactorManager(
                    generator: $totpGenerator,
                    verifier: $totpVerifier,
                    recoveryCodeGenerator: $recoveryCodeGenerator,
                    recoveryCodeVerifier: $recoveryCodeVerifier,
                    issuer: $authConfig->twoFactor->issuer,
                    recoveryCodeCount: $authConfig->twoFactor->recoveryCodeCount,
                    replayGuard: $replayGuard,
                    secretStore: $secretStore,
                    recoveryCodeHasher: $recoveryCodeHasher,
                    recoveryCodeStore: $recoveryCodeStore,
                    auditLogger: $auditLoggerForTwoFactor,
                    session: $session,
                    rateLimiter: $rateLimiter,
                    eventCollector: $eventCollector,
                );
            };

            $container->bind(TwoFactorManager::class, $twoFactorFactory);
            $container->bind(TwoFactorManagerInterface::class, $twoFactorFactory);
        }

        // Middleware
        $authenticationMiddleware = new AuthenticationMiddleware($authManager);
        $container->instance(AuthenticationMiddleware::class, $authenticationMiddleware);

        $authContextHolder = $container->has(RequestContextHolder::class)
            ? $container->get(RequestContextHolder::class)
            : null;

        /** @var RequestContextHolder|null $authContextHolder */
        $authorizationMiddleware = new AuthorizationMiddleware($gate, $auditLogger, $authContextHolder);
        $container->instance(AuthorizationMiddleware::class, $authorizationMiddleware);

        $twoFactorMiddleware = new TwoFactorMiddleware();
        $container->instance(TwoFactorMiddleware::class, $twoFactorMiddleware);

        // Step-up middleware (requires session)
        if ($session !== null) {
            $stepUpMiddleware = new StepUpMiddleware(
                $session,
                $authConfig->twoFactor->stepUpTimeoutMinutes,
            );
            $container->instance(StepUpMiddleware::class, $stepUpMiddleware);
            $middlewareRegistry->alias('step-up', $stepUpMiddleware);
        }

        // Register middleware aliases
        $middlewareRegistry->alias('auth', $authorizationMiddleware);
        $middlewareRegistry->alias('2fa', $twoFactorMiddleware);

        // Add AuthenticationMiddleware as global middleware (lightweight; only attaches SecurityContext)
        $middleware->pipe($authenticationMiddleware);
    }
}
