<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\RouteContext;
use Pulsar\Http\TrustedProxy;
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

        // F8.7: inbound `traceparent` is honoured only from a trusted upstream.
        // Build the TrustedProxy from deploy.trusted_proxies (same source as
        // SecurityWiring — which runs after this wiring, so the container
        // binding is not available here; TrustedProxy is a stateless
        // config-derived value object, so a local instance is equivalent).
        // With no proxies configured the middleware trusts nobody and every
        // client gets a fresh root span.
        $repository = $configManager->repository();
        $trustedProxies = $repository->has(DeployConfig::class)
            ? $repository->get(DeployConfig::class)->trustedProxies
            : [];
        $trustedProxy = $trustedProxies !== [] ? new TrustedProxy($trustedProxies) : null;

        $tracingMiddleware = new TracingMiddleware(
            $collector,
            $traceContextParser,
            $observabilityConfig->tracing->samplingRate,
            $randomizer,
            $routeContext,
            $trustedProxy,
        );

        // Tracing is outermost: registered first
        $middleware->pipe($tracingMiddleware);
    }
}
