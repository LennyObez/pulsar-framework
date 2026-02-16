<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

use function is_string;

/**
 * Records rate limit metrics per endpoint.
 *
 * Emits `rate_limit_requests_total` and `rate_limit_rejections_total` counters
 * via the MetricRegistry, labeled by the request path or matched route name.
 * When a 429 response is detected, also increments `rate_limit_rejections_by_ip`
 * keyed by the hashed client IP for the admin dashboard's "Top IPs" view.
 *
 * Gracefully handles a missing MetricRegistry by passing through without recording.
 */
#[Internal(reason: 'CMS HTTP middleware; implementation detail')]
final readonly class RateLimitMetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?MetricRegistry $metricRegistry = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->metricRegistry === null) {
            return $response;
        }

        $endpoint = $this->resolveEndpoint($request);
        $labels = new LabelSet(['endpoint' => $endpoint]);

        // Always count the request
        $this->metricRegistry
            ->counter('rate_limit_requests_total', 'Total rate-limited requests')
            ->increment($labels);

        // Count rejections (429 Too Many Requests)
        if ($response->getStatusCode() === 429) {
            $this->metricRegistry
                ->counter('rate_limit_rejections_total', 'Rejected rate-limited requests')
                ->increment($labels);

            // Track per-IP rejections for the admin dashboard
            $ipHash = $this->resolveIpHash($request);

            if ($ipHash !== '') {
                $ipLabels = new LabelSet(['ip' => $ipHash]);
                $this->metricRegistry
                    ->counter('rate_limit_rejections_by_ip', 'Rate limit rejections by IP hash')
                    ->increment($ipLabels);
            }
        }

        return $response;
    }

    /**
     * Resolve the endpoint identifier from the request.
     *
     * Prefers the matched route name attribute (set by the router),
     * falling back to the request path.
     */
    private function resolveEndpoint(ServerRequestInterface $request): string
    {
        $routeName = $request->getAttribute('route_name');

        if (is_string($routeName) && $routeName !== '') {
            return $routeName;
        }

        return $request->getUri()->getPath();
    }

    /**
     * Resolve a hashed IP identifier from the request.
     *
     * Prefers the `ip_hash` attribute (set by earlier middleware),
     * then falls back to REMOTE_ADDR. Returns an empty string when
     * no IP can be determined.
     */
    private function resolveIpHash(ServerRequestInterface $request): string
    {
        $ipHash = $request->getAttribute('ip_hash');

        if (is_string($ipHash) && $ipHash !== '') {
            return $ipHash;
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : '';
    }
}
