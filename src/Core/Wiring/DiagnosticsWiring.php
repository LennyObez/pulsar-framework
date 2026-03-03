<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard;
use Pulsar\Observability\Diagnostics\DiagnosticsRenderer;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Rum\RumCollector;
use Pulsar\Observability\Rum\RumController;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Routing\Router;

use function getenv;
use function is_string;

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

        // F8.1: gate `/_pulsar/diagnostics` behind a Bearer-token guard.
        // Without a configured `PULSAR_DIAGNOSTICS_TOKEN`, the guard refuses
        // every request — diagnostics are off-by-default unless an operator
        // sets the token explicitly. Token comparison is constant-time.
        $rawToken = getenv('PULSAR_DIAGNOSTICS_TOKEN');
        $expectedToken = is_string($rawToken) && $rawToken !== '' ? $rawToken : null;
        $guard = new DiagnosticsAuthGuard($expectedToken);
        $container->instance(DiagnosticsAuthGuard::class, $guard);

        $router->get('/_pulsar/diagnostics', static function (ServerRequestInterface $request) use ($container, $guard): Response {
            if (!$guard->isAuthorized($request)) {
                return Response::text(
                    'Diagnostics endpoint requires Bearer token from PULSAR_DIAGNOSTICS_TOKEN.',
                    ResponseStatus::Unauthorized,
                )->withHeader('WWW-Authenticate', 'Bearer realm="pulsar-diagnostics"');
            }

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
