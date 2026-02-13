<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Diagnostics\DiagnosticsRenderer;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Rum\RumCollector;
use Pulsar\Observability\Rum\RumController;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Routing\Router;

#[Internal]
final readonly class DiagnosticsWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        /** @var AppConfig $appConfig */
        $appConfig = $configManager->repository()->get(AppConfig::class);

        if (!$appConfig->debug) {
            return;
        }

        if (!$container->has(MetricRegistry::class)) {
            return;
        }

        $router->get('/_pulsar/diagnostics', static function () use ($container): Response {
            /** @var MetricRegistry $registry */
            $registry = $container->get(MetricRegistry::class);

            $collector = $container->has(InMemorySpanCollector::class)
                ? $container->get(InMemorySpanCollector::class)
                : null;

            $aggregator = $container->has(ErrorAggregator::class)
                ? $container->get(ErrorAggregator::class)
                : null;

            /** @var InMemorySpanCollector|null $collector */
            /** @var ErrorAggregator|null $aggregator */
            $renderer = new DiagnosticsRenderer($registry, $collector, $aggregator);

            return Response::html($renderer->render());
        });

        // RUM (Real User Monitoring): collection endpoint for frontend metrics
        /** @var MetricRegistry $metricsRegistry */
        $metricsRegistry = $container->get(MetricRegistry::class);
        $rumCollector = new RumCollector($metricsRegistry);
        $container->instance(RumCollector::class, $rumCollector);

        $rumController = new RumController($rumCollector);
        $container->instance(RumController::class, $rumController);

        $router->post('/_pulsar/rum/collect', $rumController);
    }
}
