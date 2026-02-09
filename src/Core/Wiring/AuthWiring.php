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

        // Audit logger (resolve early — used by 2FA and middleware)
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

            // Replay guard — use app-provided or fall back to in-memory
            if ($container->has(TotpReplayGuardInterface::class)) {
                /** @var TotpReplayGuardInterface $replayGuard */
                $replayGuard = $container->get(TotpReplayGuardInterface::class);
            } else {
                $replayGuard = new InMemoryTotpReplayGuard();
                $container->instance(InMemoryTotpReplayGuard::class, $replayGuard);
                $container->instance(TotpReplayGuardInterface::class, $replayGuard);
            }

            // Secret store
            $secretStore = null;
            if ($container->has(TotpSecretStoreInterface::class)) {
                /** @var TotpSecretStoreInterface $secretStore */
                $secretStore = $container->get(TotpSecretStoreInterface::class);
            } else {
                $encryptor = null;
                if ($container->has(MasterKey::class)) {
                    /** @var MasterKey $masterKey */
                    $masterKey = $container->get(MasterKey::class);
                    $encryptor = Encryptor::fromDerivedKey($masterKey, 4, 'totpscrt');
                }
                $secretStore = new InMemoryTotpSecretStore($encryptor);
                $container->instance(InMemoryTotpSecretStore::class, $secretStore);
                $container->instance(TotpSecretStoreInterface::class, $secretStore);
            }

            // Recovery code hasher + store
            $recoveryCodeHasher = null;
            $recoveryCodeStore = null;
            if ($container->has(MasterKey::class)) {
                /** @var MasterKey $masterKey */
                $masterKey = $container->get(MasterKey::class);
                $subKey = $masterKey->deriveSubKey(3, 'rcvrycod');
                $recoveryCodeHasher = new RecoveryCodeHasher($subKey);
                $container->instance(RecoveryCodeHasher::class, $recoveryCodeHasher);
            }

            if ($container->has(RecoveryCodeStoreInterface::class)) {
                /** @var RecoveryCodeStoreInterface $recoveryCodeStore */
                $recoveryCodeStore = $container->get(RecoveryCodeStoreInterface::class);
            } else {
                $recoveryCodeStore = new InMemoryRecoveryCodeStore();
                $container->instance(InMemoryRecoveryCodeStore::class, $recoveryCodeStore);
                $container->instance(RecoveryCodeStoreInterface::class, $recoveryCodeStore);
            }

            // Rate limiter (only if app provides one)
            $rateLimiter = $container->has(TwoFactorRateLimiterInterface::class)
                ? $container->get(TwoFactorRateLimiterInterface::class)
                : null;
            /** @var TwoFactorRateLimiterInterface|null $rateLimiter */

            // Event collector (only if bound — e.g., by Studio)
            $eventCollector = $container->has(AuthEventCollectorInterface::class)
                ? $container->get(AuthEventCollectorInterface::class)
                : null;
            /** @var AuthEventCollectorInterface|null $eventCollector */

            $twoFactorManager = new TwoFactorManager(
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

            $container->instance(TotpGenerator::class, $totpGenerator);
            $container->instance(TotpVerifier::class, $totpVerifier);
            $container->instance(RecoveryCodeGenerator::class, $recoveryCodeGenerator);
            $container->instance(RecoveryCodeVerifier::class, $recoveryCodeVerifier);
            $container->instance(TwoFactorManager::class, $twoFactorManager);
            $container->instance(TwoFactorManagerInterface::class, $twoFactorManager);

            // Production guardrail: warn when in-memory stores are active
            if ($logger !== null && !$authConfig->twoFactor->allowInMemory) {
                $inMemoryStores = [];
                if ($secretStore instanceof InMemoryTotpSecretStore) {
                    $inMemoryStores[] = 'InMemoryTotpSecretStore';
                }
                if ($recoveryCodeStore instanceof InMemoryRecoveryCodeStore) {
                    $inMemoryStores[] = 'InMemoryRecoveryCodeStore';
                }
                if ($replayGuard instanceof InMemoryTotpReplayGuard) {
                    $inMemoryStores[] = 'InMemoryTotpReplayGuard';
                }

                foreach ($inMemoryStores as $store) {
                    $logger->warning(
                        "In-memory 2FA store [{$store}] is active — data will not persist across restarts. Bind a persistent implementation.",
                    );
                }
            }
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

        // Add AuthenticationMiddleware as global middleware (lightweight — only attaches SecurityContext)
        $middleware->pipe($authenticationMiddleware);
    }
}
