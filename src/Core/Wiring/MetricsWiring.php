<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Diagnostics\DiagnosticsAuthGuard;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\OpenMetricsExporter;
use Pulsar\Routing\Router;

use function getenv;
use function is_string;

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

            // F8.2: gate the OpenMetrics exporter behind a Bearer-token guard.
            // The default Prometheus / OpenTelemetry scrape pattern hits this
            // endpoint on a private network, but a misconfigured load balancer
            // or a sidecar with no allowlist would expose the entire metrics
            // surface (request counts, error fingerprints, latency histograms
            // per-route) to the internet. Reuse the diagnostics guard so a
            // single env var (`PULSAR_DIAGNOSTICS_TOKEN`) protects both
            // operator endpoints.
            $guard = $container->has(DiagnosticsAuthGuard::class)
                ? $container->get(DiagnosticsAuthGuard::class)
                : new DiagnosticsAuthGuard(self::resolveOperatorToken());
            $container->instance(DiagnosticsAuthGuard::class, $guard);

            $router->get($endpoint, static function (ServerRequestInterface $request) use ($registry, $guard): Response {
                if (!$guard->isAuthorized($request)) {
                    return Response::text(
                        'Metrics endpoint requires Bearer token from PULSAR_DIAGNOSTICS_TOKEN.',
                        ResponseStatus::Unauthorized,
                    )->withHeader('WWW-Authenticate', 'Bearer realm="pulsar-metrics"');
                }

                $exporter = new OpenMetricsExporter($registry);

                return new Response(
                    headers: ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
                    body: $exporter->export(),
                );
            });
        }
    }

    private static function resolveOperatorToken(): ?string
    {
        $raw = getenv('PULSAR_DIAGNOSTICS_TOKEN');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
