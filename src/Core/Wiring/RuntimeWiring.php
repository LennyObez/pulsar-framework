<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\PersistentRuntimeFactory;
use Pulsar\Runtime\PersistentRuntimeFactoryInterface;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Tenancy\TenantContext;

#[Internal]
final readonly class RuntimeWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(RuntimeConfig::class)) {
            return;
        }

        /** @var RuntimeConfig $runtimeConfig */
        $runtimeConfig = $repository->get(RuntimeConfig::class);
        $container->instance(RuntimeConfig::class, $runtimeConfig);

        // Create the request reset registry
        $registry = new RequestResetRegistry();

        // Register evictable services (re-created per request by middleware)
        $registry->registerEvictable(SecurityContext::class);

        // Register resettable services (state reset between requests)
        if ($container->has(RequestContextHolder::class)) {
            $registry->registerResettable(RequestContextHolder::class);
        }

        if ($container->has(TenantContext::class)) {
            $registry->registerResettable(TenantContext::class);
        }

        if ($container->has(FlagEvaluationLog::class)) {
            $registry->registerResettable(FlagEvaluationLog::class);
        }

        if ($container->has(AuthManagerInterface::class)) {
            $registry->registerResettable(AuthManagerInterface::class);
        }

        $container->instance(RequestResetRegistry::class, $registry);

        // Create leak detector
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $leakDetector = new LeakDetector(logger: $logger);
        $container->instance(LeakDetector::class, $leakDetector);

        // Create request sandbox
        $sandbox = new RequestSandbox($container, $registry, $leakDetector);
        $container->instance(RequestSandbox::class, $sandbox);

        // Runtime factory (encapsulates PersistentRuntime construction)
        $runtimeFactory = new PersistentRuntimeFactory($container);
        $container->instance(PersistentRuntimeFactory::class, $runtimeFactory);
        $container->instance(PersistentRuntimeFactoryInterface::class, $runtimeFactory);
    }
}
