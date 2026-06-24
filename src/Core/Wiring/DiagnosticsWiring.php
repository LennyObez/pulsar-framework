<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard;
use Pulsar\Observability\Diagnostics\DiagnosticsController;
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

        /** @var MetricRegistry $registry */
        $registry = $container->get(MetricRegistry::class);

        $spanCollector = $container->has(InMemorySpanCollector::class)
            ? $container->get(InMemorySpanCollector::class)
            : null;

        $errorAggregator = $container->has(ErrorAggregator::class)
            ? $container->get(ErrorAggregator::class)
            : null;

        /** @var InMemorySpanCollector|null $spanCollector */
        /** @var ErrorAggregator|null $errorAggregator */
        $container->instance(
            DiagnosticsController::class,
            new DiagnosticsController($guard, $registry, $spanCollector, $errorAggregator),
        );

        // Class-based handler so the route compiles into the strict route cache.
        $router->get('/_pulsar/diagnostics', [DiagnosticsController::class, 'show']);

        // RUM (Real User Monitoring): collection endpoint for frontend metrics
        $rumCollector = new RumCollector($registry);
        $container->instance(RumCollector::class, $rumCollector);
        $container->instance(RumController::class, new RumController($rumCollector));

        // Array handler (not the invokable instance) so it also caches under --strict.
        $router->post('/_pulsar/rum/collect', [RumController::class, '__invoke']);
    }
}
