<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Config\BusinessProfileConfig;
use Pulsar\Config\BusinessProfileProvider;
use Pulsar\Config\BusinessProfileProviderInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\TwoFactorConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[Internal]
final readonly class ConfigWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();
        $environment = $configManager->environment();

        $container->instance(ConfigManager::class, $configManager);
        $container->instance(ConfigManagerInterface::class, $configManager);
        $container->instance(ConfigRepository::class, $repository);
        $container->instance(Environment::class, $environment);

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);
        $container->instance(AppConfig::class, $appConfig);

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $repository->get(ObservabilityConfig::class);
        $container->instance(ObservabilityConfig::class, $observabilityConfig);
        $container->instance(AuditConfig::class, $observabilityConfig->audit);

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $repository->get(SecurityConfig::class);
        $container->instance(SecurityConfig::class, $securityConfig);
        $container->instance(SessionConfig::class, $securityConfig->session);
        $container->instance(CsrfConfig::class, $securityConfig->csrf);
        $container->instance(SecurityHeadersConfig::class, $securityConfig->headers);

        if ($securityConfig->auth !== null) {
            $container->instance(AuthConfig::class, $securityConfig->auth);
            $container->instance(TwoFactorConfig::class, $securityConfig->auth->twoFactor);
            $container->instance(AuthorizationConfig::class, $securityConfig->auth->authorization);
        }

        // Business profile (optional; available when config/business.php exists)
        if ($repository->has(BusinessProfileConfig::class)) {
            $container->instance(BusinessProfileConfig::class, $repository->get(BusinessProfileConfig::class));
        }

        $businessProfileProvider = new BusinessProfileProvider($repository);
        $container->instance(BusinessProfileProvider::class, $businessProfileProvider);
        $container->instance(BusinessProfileProviderInterface::class, $businessProfileProvider);
    }
}
