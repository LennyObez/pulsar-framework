<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use function hrtime;

use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

/**
 * Middleware that records HTTP request metrics.
 *
 * Records:
 * - `pulsar_http_requests_total` counter (labels: method, path, status)
 * - `pulsar_http_request_duration_seconds` histogram (labels: method, path)
 * - `pulsar_http_errors_total` counter on 5xx responses (labels: method, path, status)
 */
final readonly class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MetricRegistry $registry,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $start = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $method = $request->method->value;
        $path = $request->path;
        $status = (string) $response->status->value;

        // Record request counter
        $requestLabels = new LabelSet([
            'method' => $method,
            'path' => $path,
            'status' => $status,
        ]);
        $this->registry
            ->counter('pulsar_http_requests_total', 'Total HTTP requests')
            ->increment($requestLabels);

        // Record duration histogram
        $durationLabels = new LabelSet([
            'method' => $method,
            'path' => $path,
        ]);
        $this->registry
            ->histogram('pulsar_http_request_duration_seconds', 'HTTP request duration in seconds')
            ->observe($durationSeconds, $durationLabels);

        // Record error counter on 5xx
        if ($response->status->isServerError()) {
            $errorLabels = new LabelSet([
                'method' => $method,
                'path' => $path,
                'status' => $status,
            ]);
            $this->registry
                ->counter('pulsar_http_errors_total', 'Total HTTP 5xx errors')
                ->increment($errorLabels);
        }

        return $response;
    }
}
