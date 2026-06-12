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
 * accepts the inbound header ONLY when the request arrives from a trusted
 * proxy in the configured chain (`deploy.trusted_proxies`). With no
 * TrustedProxy wired nothing is trusted: every client gets a fresh root
 * span (deny-by-default). Deployments wanting distributed-trace
 * continuity declare their upstream proxies explicitly.
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

        // F24.6: never seed the span name with the raw path — that
        // makes the cardinality unbounded for any request that throws
        // before route matching (parse errors, middleware exceptions
        // pre-router) since the `finally` block only updates the name
        // when a routeLabel is available. Start with a placeholder
        // that the post-route logic always replaces with a bounded
        // label or 'unmatched'.
        $span = new Span(
            name: sprintf('HTTP %s pending', $method),
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
            // F24.6: always replace the placeholder name with either a
            // bounded route label or the literal `unmatched` so the
            // span cardinality stays under control even when the
            // request threw before route matching.
            $routeLabel = $this->routeContext?->label();
            $span->name = sprintf(
                'HTTP %s %s',
                $request->getMethod(),
                $routeLabel ?? 'unmatched',
            );

            if ($routeLabel !== null && $routeLabel !== 'unmatched') {
                $span->setAttribute('http.route', $routeLabel);
            }

            $span->end();
            $this->collector->onEnd($span);
        }
    }

    /**
     * F8.7: traceparent is honoured only when the request comes from a
     * trusted proxy. With no TrustedProxy wired, NOTHING is trusted
     * (deny-by-default): otherwise any unauthenticated client could force
     * sampling (`sampled=01` on every request exhausts the collector) or
     * inject forged trace/span ids into the topology. Deployments that want
     * distributed-trace continuity declare their upstream in
     * `deploy.trusted_proxies`; TracingWiring then wires the TrustedProxy
     * that validates REMOTE_ADDR against that chain.
     */
    private function shouldHonourInboundTraceparent(ServerRequestInterface $request): bool
    {
        if ($this->trustedProxy === null) {
            return false;
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

        // F8.8: scale to 1_000_000 so the smallest representable
        // sampling rate is 1 in a million (1e-6). The previous
        // `getInt(0, 999)` clamped any rate < 1e-3 to threshold 0,
        // silently disabling sampling for legitimate operator
        // values like `samplingRate = 1e-4` (one in 10K). The
        // wider range covers production traffic from low-volume
        // services up to multi-million-rps fleets without
        // introducing a 64-bit codepath.
        $random = $this->randomizer->getInt(0, 999_999);
        $threshold = (int) ($this->samplingRate * 1_000_000.0);

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
