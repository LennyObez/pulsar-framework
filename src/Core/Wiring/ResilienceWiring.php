<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Controller\HealthController;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\HealthCheck\CacheHealthCheck;
use Pulsar\Resilience\HealthCheck\DatabaseHealthCheck;
use Pulsar\Resilience\HealthCheck\DiskHealthCheck;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\Repair\RepairRunner;
use Pulsar\Resilience\Repair\RepairRunnerInterface;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\FipsComplianceCheck;

use function in_array;

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

        // Register built-in health checks. FIPS compliance is checked here so a
        // FIPS-regulated deployment surfaces a broken crypto posture on /health
        // instead of discovering it during an incident.
        $healthCheckRunner->register(new DiskHealthCheck());
        $healthCheckRunner->register(new FipsComplianceCheck());

        // Health endpoint: lazily registers checks that depend on services
        // wired after ResilienceWiring (e.g. CacheWiring).
        // Registered at both /_pulsar/health and /health for convenience.
        $healthHandler = static function () use ($container, $healthCheckRunner): Response {
            // Register cache health check on first request when PSR-16 cache is available
            if ($container->has(CacheInterface::class)) {
                /** @var CacheInterface $cache */
                $cache = $container->get(CacheInterface::class);

                if (!self::hasCheck($healthCheckRunner, 'cache')) {
                    $healthCheckRunner->register(new CacheHealthCheck($cache));
                }
            }

            // Same lazy pattern for database connectivity: DatabaseWiring runs
            // before ResilienceWiring, but the connection may be bound by an
            // extension or replaced at runtime, so resolve it per request.
            if ($container->has(ConnectionManagerInterface::class)) {
                /** @var ConnectionManagerInterface $connectionManager */
                $connectionManager = $container->get(ConnectionManagerInterface::class);

                if (!self::hasCheck($healthCheckRunner, 'database')) {
                    $healthCheckRunner->register(new DatabaseHealthCheck($connectionManager));
                }
            }

            $controller = new HealthController($healthCheckRunner);

            return $controller();
        };

        $router->get('/_pulsar/health', $healthHandler);
        $router->get('/health', $healthHandler);

        // Repair runner
        $repairRunner = new RepairRunner();
        $container->instance(RepairRunner::class, $repairRunner);
        $container->instance(RepairRunnerInterface::class, $repairRunner);
    }

    /**
     * Check whether a health check with the given name is already registered.
     */
    private static function hasCheck(HealthCheckRunner $runner, string $name): bool
    {
        return in_array($name, $runner->names(), true);
    }
}
