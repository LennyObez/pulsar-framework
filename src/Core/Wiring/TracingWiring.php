<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\W3CTraceContextParser;
use Pulsar\Routing\Router;
use Random\Randomizer;

#[Internal]
final readonly class TracingWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);

        if (!$observabilityConfig->tracing->enabled) {
            return;
        }

        $collector = new InMemorySpanCollector();
        $container->instance(InMemorySpanCollector::class, $collector);

        /** @var Randomizer $randomizer */
        $randomizer = $container->get(Randomizer::class);

        // Create shared RouteContext (populated after route matching)
        if (!$container->has(RouteContext::class)) {
            $routeContext = new RouteContext();
            $container->instance(RouteContext::class, $routeContext);
        }

        /** @var RouteContext $routeContext */
        $routeContext = $container->get(RouteContext::class);

        $traceContextParser = new W3CTraceContextParser();

        $tracingMiddleware = new TracingMiddleware(
            $collector,
            $traceContextParser,
            $observabilityConfig->tracing->samplingRate,
            $randomizer,
            $routeContext,
        );

        // Tracing is outermost: registered first
        $middleware->pipe($tracingMiddleware);
    }
}
