<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\RouteContext;
use Pulsar\Http\TrustedProxy;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceContextParserInterface;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function is_string;
use function sprintf;

/**
 * Middleware that creates a root span for each HTTP request.
 *
 * Parses incoming `traceparent` header for distributed trace propagation.
 * Attaches trace context and root span to request attributes. Sets span
 * status from response status code and adds `traceparent` to response.
 *
 * F8.7: incoming `traceparent` from anywhere is dangerous — a hostile
 * client can spoof trace ids into the topology, force `sampled=01` to
 * bypass our sampling rate (collector memory exhaustion), or attempt
 * to correlate with internal trace ids leaked elsewhere. The middleware
 * accepts the inbound header only when (a) no TrustedProxy is wired
 * (single-tenant deployments behind their own auth), or (b) the request
 * arrives from a trusted proxy in the configured chain. Any other
 * source has its `traceparent` discarded and a fresh root span is
 * minted.
 */
final readonly class TracingMiddleware implements MiddlewareInterface
{
    private Randomizer $randomizer;

    public function __construct(
        private SpanProcessorInterface $collector,
        private TraceContextParserInterface $traceContextParser,
        private float $samplingRate = 1.0,
        ?Randomizer $randomizer = null,
        private ?RouteContext $routeContext = null,
        private ?TrustedProxy $trustedProxy = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * @throws RandomException
     */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // F8.7: only honour `traceparent` from a trusted upstream.
        // Untrusted clients get a fresh root span — their inbound
        // header is silently discarded so trace topology and
        // sampling decisions stay under our control.
        $parentContext = null;

        if ($this->shouldHonourInboundTraceparent($request)) {
            $traceparent = $request->getHeaderLine('traceparent');

            if ($traceparent !== '') {
                $parentContext = $this->traceContextParser->parse($traceparent);
            }
        }

        // Determine if this request should be sampled
        if (!$this->shouldSample($parentContext)) {
            return $handler->handle($request);
        }

        // Create trace context: child of incoming or new root
        $context = $parentContext !== null
            ? $parentContext->createChild()
            : TraceContext::create();

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Create root span
        $spanName = sprintf('HTTP %s %s', $method, $path);
        $span = new Span(
            name: $spanName,
            context: $context,
            parentSpanId: $parentContext?->spanId,
        );

        $span->setAttribute('http.method', $method);
        $span->setAttribute('http.path', $path);
        $span->setAttribute('http.url', (string) $request->getUri());

        // Attach to request attributes for downstream use
        $request = $request
            ->withAttribute('_trace_context', $context)
            ->withAttribute('_root_span', $span);

        try {
            $response = $handler->handle($request);

            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->status = $this->resolveSpanStatus($response->getStatusCode());

            // Add traceparent to response
            return $response->withHeader(
                'traceparent',
                $this->traceContextParser->serialize($context),
            );
        } finally {
            // Update span name with resolved route for bounded cardinality
            $routeLabel = $this->routeContext?->label();

            if ($routeLabel !== null && $routeLabel !== 'unmatched') {
                $span->name = sprintf('HTTP %s %s', $request->getMethod(), $routeLabel);
                $span->setAttribute('http.route', $routeLabel);
            }

            $span->end();
            $this->collector->onEnd($span);
        }
    }

    /**
     * F8.7: traceparent is honoured only when the request comes from a
     * trusted proxy (or no TrustedProxy was wired, in which case we
     * trust every direct caller — single-tenant / behind-own-auth
     * deployments). The TrustedProxy class already validates remote
     * IP against a configurable proxy chain.
     */
    private function shouldHonourInboundTraceparent(ServerRequestInterface $request): bool
    {
        if ($this->trustedProxy === null) {
            return true;
        }

        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!is_string($remoteAddr) || $remoteAddr === '') {
            return false;
        }

        return $this->trustedProxy->isTrustedSource($remoteAddr);
    }

    /**
     * @throws RandomException
     */
    private function shouldSample(?TraceContext $parentContext): bool
    {
        // If parent is sampled, always sample
        if ($parentContext !== null) {
            return $parentContext->isSampled();
        }

        // Probabilistic sampling
        if ($this->samplingRate >= 1.0) {
            return true;
        }

        if ($this->samplingRate <= 0.0) {
            return false;
        }

        $random = $this->randomizer->getInt(0, 999);

        $threshold = (int) ($this->samplingRate * 1000.0);

        return $random < $threshold;
    }

    private function resolveSpanStatus(int $statusCode): SpanStatus
    {
        if ($statusCode >= 500) {
            return SpanStatus::Error;
        }

        if ($statusCode >= 400) {
            return SpanStatus::Unset;
        }

        return SpanStatus::Ok;
    }
}
