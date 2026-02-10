<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\OpenMetricsExporter;
use Pulsar\Routing\Router;

#[Internal]
final readonly class MetricsWiring implements ServiceWiringInterface
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

        if (!$observabilityConfig->metrics->enabled) {
            return;
        }

        $registry = new MetricRegistry();
        $container->instance(MetricRegistry::class, $registry);

        // Create shared RouteContext if not already created by tracing
        if (!$container->has(RouteContext::class)) {
            $routeContext = new RouteContext();
            $container->instance(RouteContext::class, $routeContext);
        }

        /** @var RouteContext $routeContext */
        $routeContext = $container->get(RouteContext::class);

        $metricsMiddleware = new MetricsMiddleware($registry, $routeContext);

        // Metrics is inner: registered after tracing
        $middleware->pipe($metricsMiddleware);

        // Register OpenMetrics endpoint if enabled
        if ($observabilityConfig->metrics->exporterEnabled) {
            $endpoint = $observabilityConfig->metrics->exporterEndpoint;
            $router->get($endpoint, static function () use ($registry): Response {
                $exporter = new OpenMetricsExporter($registry);

                return new Response(
                    headers: ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
                    body: $exporter->export(),
                );
            });
        }
    }
}
