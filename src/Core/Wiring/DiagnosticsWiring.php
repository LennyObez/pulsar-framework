<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard;
use Pulsar\Observability\Diagnostics\DiagnosticsController;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Rum\RumCollector;
use Pulsar\Observability\Rum\RumController;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Routing\RouteAccessRegistrar;
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

        // Gate `/_pulsar/diagnostics` behind a Bearer-token guard.
        // Without a configured `PULSAR_DIAGNOSTICS_TOKEN`, the guard refuses
        // every request — diagnostics are off-by-default unless an operator
        // sets the token explicitly. Token comparison is constant-time.
        // Resolved through the Environment so a token set in .env is honoured
        // (a bare getenv() would miss .env-only values).
        $rawToken = $configManager->environment()->get('PULSAR_DIAGNOSTICS_TOKEN');
        $expectedToken = $rawToken !== null && $rawToken !== '' ? $rawToken : null;
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

        $routes = new RouteAccessRegistrar($router, $middlewareRegistry);

        // Operator-only, and the credential is a Bearer token rather than a
        // session: the callers are on-call engineers and scrapers. The check runs
        // inside DiagnosticsController::show() via the guard built above, so it
        // does not depend on this deployment's middleware pipeline containing
        // anything in particular. Class-based handler so the route compiles into
        // the strict route cache.
        $routes->operatorRoute(
            [Method::GET],
            '/_pulsar/diagnostics',
            [DiagnosticsController::class, 'show'],
            'pulsar.diagnostics',
            'Operator dashboard exposing request counts, latency histograms, error '
                . 'fingerprints and span samples; DiagnosticsAuthGuard requires the '
                . 'PULSAR_DIAGNOSTICS_TOKEN Bearer token and refuses every request when unset.',
        );

        // RUM (Real User Monitoring): collection endpoint for frontend metrics
        $rumCollector = new RumCollector($registry);
        $container->instance(RumCollector::class, $rumCollector);
        $container->instance(RumController::class, new RumController($rumCollector));

        // Deliberately anonymous: it collects web-vitals from visitors who have no
        // session, so no credential exists to demand. What bounds it is stated on
        // RumController -- a same-origin Origin header, a 16 KiB body cap, a
        // 100-entry batch cap, and a metric-label budget so a caller cannot mint
        // unbounded time series. Registered only in debug builds, by the guard at
        // the top of this method.
        // Array handler (not the invokable instance) so it also caches under --strict.
        $routes->publicRoute(
            [Method::POST],
            '/_pulsar/rum/collect',
            [RumController::class, '__invoke'],
            'pulsar.rum.collect',
            'Anonymous browsers report their own web-vitals; RumController accepts only '
                . 'same-origin POSTs under 16 KiB and RumUrlLabels bounds the metric '
                . 'cardinality a caller can create. Debug builds only.',
        );
    }
}
