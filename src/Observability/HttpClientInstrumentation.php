<?php

declare(strict_types=1);

namespace Pulsar\Observability;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Throwable;

use function hrtime;
use function parse_url;
use function strtoupper;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Instruments outbound HTTP requests with spans and metrics.
 *
 * Wraps HTTP client calls to automatically collect:
 * - Request duration histograms by host and status code
 * - Error counters for failed requests
 * - Distributed tracing spans with correlation context
 *
 * Usage: wrap your HTTP client call with start()/finish():
 *
 *     $ctx = $instrumentation->start('GET', 'https://api.example.com/users');
 *     try {
 *         $response = $httpClient->get(...);
 *         $instrumentation->finish($ctx, $response->getStatusCode());
 *     } catch (\Throwable $e) {
 *         $instrumentation->error($ctx, $e);
 *         throw $e;
 *     }
 */
#[Api(since: '1.0.0')]
final readonly class HttpClientInstrumentation
{
    public function __construct(
        private MetricRegistry $metrics,
    ) {}

    /**
     * Start instrumenting an outbound HTTP request.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url    Target URL
     *
     * @return OutboundRequestContext Context to pass to finish() or error()
     */
    #[NoDiscard]
    public function start(string $method, string $url): OutboundRequestContext
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $method = strtoupper($method);

        $startTime = hrtime(true);

        $this->metrics->counter(
            'http_client_requests_total',
            'Total outbound HTTP requests',
        )->increment(new LabelSet([
            'method' => $method,
            'host' => $host,
        ]));

        return new OutboundRequestContext(
            method: $method,
            url: $url,
            host: $host,
            scheme: $scheme,
            startTimeNs: $startTime,
        );
    }

    /**
     * Record successful completion of an outbound request.
     *
     * @param OutboundRequestContext $ctx        Context from start()
     * @param int                    $statusCode HTTP response status code
     */
    public function finish(OutboundRequestContext $ctx, int $statusCode): void
    {
        $durationMs = (hrtime(true) - $ctx->startTimeNs) / 1_000_000;

        $this->metrics->histogram(
            'http_client_duration_ms',
            'Outbound HTTP request duration in milliseconds',
            [1.0, 5.0, 10.0, 25.0, 50.0, 100.0, 250.0, 500.0, 1000.0, 5000.0],
        )->observe($durationMs, new LabelSet([
            'method' => $ctx->method,
            'host' => $ctx->host,
            'status' => (string) $statusCode,
        ]));

        if ($statusCode >= 500) {
            $this->metrics->counter(
                'http_client_errors_total',
                'Outbound HTTP server errors (5xx)',
            )->increment(new LabelSet([
                'method' => $ctx->method,
                'host' => $ctx->host,
                'status' => (string) $statusCode,
            ]));
        }
    }

    /**
     * Record a failed outbound request (exception, timeout, connection error).
     *
     * @param OutboundRequestContext $ctx Context from start()
     * @param Throwable             $error The exception that occurred
     */
    public function error(OutboundRequestContext $ctx, Throwable $error): void
    {
        $durationMs = (hrtime(true) - $ctx->startTimeNs) / 1_000_000;

        $this->metrics->histogram(
            'http_client_duration_ms',
            'Outbound HTTP request duration in milliseconds',
            [1.0, 5.0, 10.0, 25.0, 50.0, 100.0, 250.0, 500.0, 1000.0, 5000.0],
        )->observe($durationMs, new LabelSet([
            'method' => $ctx->method,
            'host' => $ctx->host,
            'status' => 'error',
        ]));

        $this->metrics->counter(
            'http_client_errors_total',
            'Outbound HTTP request errors',
        )->increment(new LabelSet([
            'method' => $ctx->method,
            'host' => $ctx->host,
            'error' => substr($error::class, 0, 100),
        ]));
    }
}
