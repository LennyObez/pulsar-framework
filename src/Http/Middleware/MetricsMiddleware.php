<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Throwable;

use function hrtime;
use function in_array;

/**
 * Middleware that records HTTP request metrics.
 *
 * Records:
 * - `pulsar_http_requests_total` counter (labels: method, route, status)
 * - `pulsar_http_request_duration_seconds` histogram (labels: method, route)
 * - `pulsar_http_errors_total` counter on 5xx responses (labels: method, route, status)
 *
 * Uses RouteContext (populated by Kernel after route matching) for bounded
 * label cardinality. Falls back to the raw path when RouteContext is null.
 */
final readonly class MetricsMiddleware implements MiddlewareInterface
{
    /**
     * Paths the middleware refuses to record metrics for. The
     * scrape endpoint itself (`/metrics`) and the related diagnostics
     * endpoints would otherwise auto-monitor every Prometheus pull,
     * producing recursive `pulsar_http_requests_total{route="/metrics"}`
     * counters that grow once per scrape interval and dwarf real
     * request signal. Excluding them keeps the time series clean.
     */
    private const array EXCLUDED_PATHS = [
        '/metrics',
        '/_pulsar/metrics',
        '/_pulsar/diagnostics',
        '/_pulsar/health',
    ];

    public function __construct(
        private MetricRegistry $registry,
        private ?RouteContext $routeContext = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Short-circuit before resetting RouteContext + capturing
        // the start time so the excluded path round-trip is genuinely
        // metric-free, not just absent from the registry.
        if (in_array($request->getUri()->getPath(), self::EXCLUDED_PATHS, true)) {
            return $handler->handle($request);
        }

        $this->routeContext?->reset();
        $start = hrtime(true);

        try {
            $response = $handler->handle($request);

            return $response;
        } finally {
            try {
                $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;

                // When no route was matched (404 path, fall-through, or
                // routeContext not wired), do NOT emit the raw URI as the
                // metric label. Routes like `/users/{id}` carry an UUID per
                // request, so the raw path explodes the metric registry's
                // series count — Prometheus failure mode #1. Bind to the
                // sentinel `unmatched` instead so unmatched traffic is
                // visible but bounded.
                $label = $this->routeContext?->label() ?? 'unmatched';
                $method = $request->getMethod();
                $status = (string) (isset($response) ? $response->getStatusCode() : 500);

                $requestLabels = new LabelSet([
                    'method' => $method,
                    'route' => $label,
                    'status' => $status,
                ]);
                $this->registry
                    ->counter('pulsar_http_requests_total', 'Total HTTP requests')
                    ->increment($requestLabels);

                $durationLabels = new LabelSet([
                    'method' => $method,
                    'route' => $label,
                ]);
                $this->registry
                    ->histogram('pulsar_http_request_duration_seconds', 'HTTP request duration in seconds')
                    ->observe($durationSeconds, $durationLabels);

                if (isset($response) && $response->getStatusCode() >= 500) {
                    $errorLabels = new LabelSet([
                        'method' => $method,
                        'route' => $label,
                        'status' => $status,
                    ]);
                    $this->registry
                        ->counter('pulsar_http_errors_total', 'Total HTTP 5xx errors')
                        ->increment($errorLabels);
                }
            } catch (Throwable) {
                // Metrics recording must never mask the original exception
            }
        }
    }
}
