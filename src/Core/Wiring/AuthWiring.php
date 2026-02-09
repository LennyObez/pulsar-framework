<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
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
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\Password\PasswordHasher;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SecurityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditLogger;
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
            $recoveryCodeGenerator = new RecoveryCodeGenerator($randomizer);
            $recoveryCodeVerifier = new RecoveryCodeVerifier();

            $twoFactorManager = new TwoFactorManager(
                generator: $totpGenerator,
                verifier: $totpVerifier,
                recoveryCodeGenerator: $recoveryCodeGenerator,
                recoveryCodeVerifier: $recoveryCodeVerifier,
                issuer: $authConfig->twoFactor->issuer,
                recoveryCodeCount: $authConfig->twoFactor->recoveryCodeCount,
            );

            $container->instance(TotpGenerator::class, $totpGenerator);
            $container->instance(TotpVerifier::class, $totpVerifier);
            $container->instance(RecoveryCodeGenerator::class, $recoveryCodeGenerator);
            $container->instance(RecoveryCodeVerifier::class, $recoveryCodeVerifier);
            $container->instance(TwoFactorManager::class, $twoFactorManager);
            $container->instance(TwoFactorManagerInterface::class, $twoFactorManager);
        }

        // Middleware
        $authenticationMiddleware = new AuthenticationMiddleware($authManager);
        $container->instance(AuthenticationMiddleware::class, $authenticationMiddleware);

        $auditLogger = $container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null;

        $authContextHolder = $container->has(RequestContextHolder::class)
            ? $container->get(RequestContextHolder::class)
            : null;

        /** @var AuditLogger|null $auditLogger */
        /** @var RequestContextHolder|null $authContextHolder */
        $authorizationMiddleware = new AuthorizationMiddleware($gate, $auditLogger, $authContextHolder);
        $container->instance(AuthorizationMiddleware::class, $authorizationMiddleware);

        $twoFactorMiddleware = new TwoFactorMiddleware();
        $container->instance(TwoFactorMiddleware::class, $twoFactorMiddleware);

        // Register middleware aliases
        $middlewareRegistry->alias('auth', $authorizationMiddleware);
        $middlewareRegistry->alias('2fa', $twoFactorMiddleware);

        // Add AuthenticationMiddleware as global middleware (lightweight — only attaches SecurityContext)
        $middleware->pipe($authenticationMiddleware);
    }
}
