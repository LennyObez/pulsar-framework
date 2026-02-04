<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\Repair\RepairRunner;
use Pulsar\Resilience\Repair\RepairRunnerInterface;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Routing\Router;

#[Internal]
final readonly class ResilienceWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(ResilienceConfig::class)) {
            return;
        }

        /** @var ResilienceConfig $resilienceConfig */
        $resilienceConfig = $repository->get(ResilienceConfig::class);
        $container->instance(ResilienceConfig::class, $resilienceConfig);
        $container->instance(RetryConfig::class, $resilienceConfig->retry);
        $container->instance(CircuitBreakerConfig::class, $resilienceConfig->circuitBreaker);
        $container->instance(HealthCheckConfig::class, $resilienceConfig->healthCheck);

        if (!$resilienceConfig->enabled) {
            return;
        }

        // Retry policy (default)
        $retryPolicy = RetryPolicy::fromConfig($resilienceConfig->retry);
        $container->instance(RetryPolicy::class, $retryPolicy);

        // Circuit breaker registry
        $cbRegistry = new CircuitBreakerRegistry($resilienceConfig->circuitBreaker);
        $container->instance(CircuitBreakerRegistry::class, $cbRegistry);

        // Health check runner
        $healthCheckRunner = new HealthCheckRunner();
        $container->instance(HealthCheckRunner::class, $healthCheckRunner);
        $container->instance(HealthCheckRunnerInterface::class, $healthCheckRunner);

        // Repair runner
        $repairRunner = new RepairRunner();
        $container->instance(RepairRunner::class, $repairRunner);
        $container->instance(RepairRunnerInterface::class, $repairRunner);
    }
}
